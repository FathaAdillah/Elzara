<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Platform\Outbox\QueueRouting;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AdminCatalogCrudTest extends TestCase
{
    use AdminCatalogSession;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    public function test_permissions_csrf_and_idempotency_guard_every_mutation(): void
    {
        $this->api('GET', '/api/v1/admin/catalog/products')->assertUnauthorized()->assertHeader('X-RateLimit-Policy', 'admin');
        $user = $this->loginAdmin();
        $this->api('GET', '/api/v1/admin/catalog/brands')->assertForbidden();
        $this->grant($user, 'catalog.view');
        $this->api('GET', '/api/v1/admin/catalog/brands')->assertOk()->assertJsonPath('data', [])->assertHeader('Cache-Control', 'no-store, private');
        $this->api('POST', '/api/v1/admin/catalog/brands', ['slug' => 'acme', 'name' => 'Acme'], true)->assertForbidden();
        $this->grant($user, 'catalog.create');
        $this->api('POST', '/api/v1/admin/catalog/brands', ['slug' => 'acme', 'name' => 'Acme'], true, csrf: false)->assertStatus(419);
        $this->api('POST', '/api/v1/admin/catalog/brands', ['slug' => 'acme', 'name' => 'Acme'])->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->api('POST', '/api/v1/admin/catalog/brands', ['slug' => 'acme', 'name' => 'Acme'], true)->assertCreated()->assertJsonPath('data.version', 1);
        $this->api('PATCH', '/api/v1/admin/catalog/brands/'.DB::table('brands')->value('id'), ['name' => 'X', 'version' => 1], true)->assertForbidden();
        $this->api('GET', '/api/v1/admin/catalog/brands?page=2')->assertUnprocessable();
    }

    public function test_taxonomy_crud_uses_optimistic_version_and_blocks_deleting_referenced_rows(): void
    {
        $this->loginAdmin('catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete');
        $category = $this->api('POST', '/api/v1/admin/catalog/categories', ['slug' => 'figures', 'name_key' => 'catalog.category.figures', 'reason' => 'Kategori baru'], true)
            ->assertCreated()->json('data');
        $this->api('POST', '/api/v1/admin/catalog/categories', ['slug' => 'figures', 'name_key' => 'dup'], true)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->api('POST', '/api/v1/admin/catalog/categories', ['slug' => 'Bad Slug', 'name_key' => 'x'], true)->assertUnprocessable();
        $this->api('POST', '/api/v1/admin/catalog/collections', ['slug' => 'c1', 'name_key' => 'k', 'mystery' => 1], true)->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/mystery');

        $id = $category['id'];
        $this->api('PATCH', "/api/v1/admin/catalog/categories/{$id}", ['name_key' => 'catalog.category.figurines'], true)->assertUnprocessable();
        $this->api('PATCH', "/api/v1/admin/catalog/categories/{$id}", ['name_key' => 'catalog.category.figurines', 'version' => 1], true)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.name_key', 'catalog.category.figurines');
        $this->api('PATCH', "/api/v1/admin/catalog/categories/{$id}", ['name_key' => 'stale', 'version' => 1], true)
            ->assertConflict()->assertJsonPath('code', 'VERSION_STALE');
        $this->assertDatabaseHas('categories', ['id' => $id, 'name_key' => 'catalog.category.figurines', 'version' => 2]);

        $this->assertSame(0, DB::table('outbox_events')->count());
        DB::table('products')->insert(['slug' => 'p-1', 'name' => 'P', 'category_id' => $id, 'origin_country' => 'ID']);
        $this->api('PATCH', "/api/v1/admin/catalog/categories/{$id}", ['is_active' => false, 'version' => 2], true)->assertOk()->assertJsonPath('data.version', 3);
        $this->assertSame(1, DB::table('outbox_events')->where('event_name', 'ProductUpdated')->count(), 'perubahan kategori memicu reindex produknya');
        $this->api('DELETE', "/api/v1/admin/catalog/categories/{$id}?version=3", idempotent: true)->assertConflict()->assertJsonPath('code', 'RESOURCE_IN_USE');
        $this->api('DELETE', "/api/v1/admin/catalog/categories/{$id}", idempotent: true)->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $id]);

        $priceList = $this->api('POST', '/api/v1/admin/catalog/price-lists', ['code' => 'RESELLER', 'name' => 'Reseller', 'kind' => 'wholesale'], true)->assertCreated()->json('data');
        $this->api('DELETE', "/api/v1/admin/catalog/price-lists/{$priceList['id']}?version=1", idempotent: true)->assertNoContent();
        $this->api('GET', "/api/v1/admin/catalog/price-lists/{$priceList['id']}")->assertNotFound();
        $this->assertSame(['catalog.create', 'catalog.update', 'catalog.update', 'catalog.create', 'catalog.delete'],
            DB::table('audit_logs')->orderBy('id')->pluck('action')->all());
    }

    public function test_product_lifecycle_records_outbox_events_on_the_imports_queue(): void
    {
        $taxonomy = $this->seedTaxonomy();
        $this->loginAdmin('catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete');
        $product = $this->api('POST', '/api/v1/admin/catalog/products', [
            'slug' => 'aurora-hoodie', 'name' => 'Aurora Hoodie', 'category_id' => $taxonomy['category'], 'brand_id' => $taxonomy['brand'], 'origin_country' => 'ID',
            'status' => 'active', 'fulfillment_modes' => ['rts', 'po'], 'collection_ids' => [$taxonomy['collection']],
            'variants' => [['sku' => 'AUR-HD-M', 'label' => 'M', 'base_price' => 350000], ['sku' => 'AUR-HD-L', 'label' => 'L', 'base_price' => 365000]],
        ], true)->assertCreated()->assertJsonPath('data.fulfillment_modes', ['rts', 'po'])->assertJsonPath('data.collection_ids', [$taxonomy['collection']])
            ->assertJsonCount(2, 'data.variants')->json('data');
        $id = $product['id'];

        $this->api('POST', '/api/v1/admin/catalog/products', ['slug' => 'other', 'name' => 'O', 'category_id' => $taxonomy['category'], 'origin_country' => 'ID',
            'variants' => [['sku' => 'AUR-HD-M', 'label' => 'dup', 'base_price' => 1]]], true)->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/variants/0/sku');
        $this->api('PATCH', "/api/v1/admin/catalog/products/{$id}", ['name' => 'Aurora Hoodie v2', 'version' => 1, 'fulfillment_modes' => ['request']], true)
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.fulfillment_modes', ['request']);
        $this->api('PATCH', "/api/v1/admin/catalog/products/{$id}", ['name' => 'Lost update', 'version' => 1], true)->assertConflict();

        $variant = $this->api('POST', "/api/v1/admin/catalog/products/{$id}/variants", ['sku' => 'AUR-HD-XL', 'label' => 'XL', 'base_price' => 380000], true)
            ->assertCreated()->json('data');
        $this->api('PATCH', "/api/v1/admin/catalog/variants/{$variant['id']}", ['base_price' => 390000, 'version' => 1], true)->assertOk()->assertJsonPath('data.version', 2);
        $this->api('DELETE', "/api/v1/admin/catalog/variants/{$variant['id']}?version=2", idempotent: true)->assertNoContent();
        $this->api('GET', "/api/v1/admin/catalog/products/{$id}")->assertOk()->assertJsonCount(2, 'data.variants');

        $priceList = DB::table('price_lists')->insertGetId(['code' => 'MEMBER', 'name' => 'Member', 'kind' => 'member']);
        $variantId = $product['variants'][0]['id'];
        $this->api('PUT', "/api/v1/admin/catalog/price-lists/{$priceList}/entries", ['version' => 1, 'entries' => [['variant_id' => $variantId, 'price' => 330000]]], true)
            ->assertOk()->assertJsonPath('data.0.price', 330000);
        $this->api('PUT', "/api/v1/admin/catalog/price-lists/{$priceList}/entries", ['version' => 1, 'entries' => []], true)->assertConflict();

        $this->api('GET', '/api/v1/admin/catalog/products?status=active&limit=1')->assertOk()->assertJsonPath('data.0.slug', 'aurora-hoodie')->assertJsonPath('meta.limit', 1);
        $this->api('DELETE', "/api/v1/admin/catalog/products/{$id}?version=2", idempotent: true)->assertNoContent();
        $this->api('GET', "/api/v1/admin/catalog/products/{$id}")->assertNotFound();
        $this->assertDatabaseHas('products', ['id' => $id, 'status' => 'archived', 'version' => 3]);

        $events = DB::table('outbox_events')->where('event_name', 'ProductUpdated')->orderBy('id')->get();
        $this->assertCount(6, $events);
        $this->assertSame('imports', QueueRouting::for('ProductUpdated'));
        foreach ($events as $event) {
            $this->assertSame($id, (int) $event->aggregate_id);
        }
        $payload = json_decode($events[1]->payload, true);
        $this->assertSame(['name', 'fulfillment_modes'], $payload['changed_fields']);
        $this->assertSame($id, $payload['product_id']);
        $this->assertCount(2, $payload['variant_ids']);
    }
}
