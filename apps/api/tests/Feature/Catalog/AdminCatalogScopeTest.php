<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Application\ProductCsv;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** Doc 06 §2: role_scopes kategori membatasi baris katalog yang boleh dilihat/diubah, termasuk subkategori. */
final class AdminCatalogScopeTest extends TestCase
{
    use AdminCatalogSession;
    use DatabaseTransactions;

    private const ALL = ['catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete'];

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $apparel = DB::table('categories')->insertGetId(['slug' => 'apparel', 'name_key' => 'catalog.category.apparel']);
        $this->ids = [
            'apparel' => $apparel,
            'hoodies' => DB::table('categories')->insertGetId(['slug' => 'hoodies', 'name_key' => 'catalog.category.hoodies', 'parent_id' => $apparel]),
            'toys' => DB::table('categories')->insertGetId(['slug' => 'toys', 'name_key' => 'catalog.category.toys']),
            'brand' => DB::table('brands')->insertGetId(['slug' => 'snowego', 'name' => 'Snowego', 'country' => 'ID']),
        ];
        foreach (['tee' => 'apparel', 'hoodie' => 'hoodies', 'plush' => 'toys'] as $slug => $category) {
            $this->ids[$slug] = DB::table('products')->insertGetId(['slug' => $slug, 'name' => ucfirst($slug), 'category_id' => $this->ids[$category], 'origin_country' => 'ID']);
            $this->ids["{$slug}-variant"] = DB::table('product_variants')->insertGetId(['product_id' => $this->ids[$slug], 'sku' => strtoupper($slug).'-1', 'label' => 'One', 'base_price' => 100000]);
        }
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    public function test_reads_are_limited_to_the_scoped_category_subtree(): void
    {
        $this->scopedOperator(['category' => [$this->ids['apparel']]]);

        $this->assertEqualsCanonicalizing(['tee', 'hoodie'], array_column($this->api('GET', '/api/v1/admin/catalog/products')->assertOk()->json('data'), 'slug'));
        $this->api('GET', "/api/v1/admin/catalog/products/{$this->ids['hoodie']}")->assertOk();
        $this->api('GET', "/api/v1/admin/catalog/products/{$this->ids['plush']}")->assertNotFound();
        $this->assertEqualsCanonicalizing([$this->ids['apparel'], $this->ids['hoodies']], array_column($this->api('GET', '/api/v1/admin/catalog/categories')->assertOk()->json('data'), 'id'));
        $this->api('GET', "/api/v1/admin/catalog/categories/{$this->ids['toys']}")->assertNotFound();
        $this->api('GET', '/api/v1/admin/catalog/brands')->assertOk()->assertJsonPath('data.0.id', $this->ids['brand']);

        $export = $this->api('GET', '/api/v1/admin/catalog/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('TEE-1', $export);
        $this->assertStringContainsString('HOODIE-1', $export);
        $this->assertStringNotContainsString('PLUSH-1', $export);

        $priceList = DB::table('price_lists')->insertGetId(['code' => 'MEMBER', 'name' => 'Member', 'kind' => 'member']);
        foreach (['tee-variant', 'plush-variant'] as $variant) {
            DB::table('price_list_entries')->insert(['price_list_id' => $priceList, 'variant_id' => $this->ids[$variant], 'price' => 90000, 'currency_code' => 'IDR']);
        }
        $this->assertSame([$this->ids['tee-variant']], array_column($this->api('GET', "/api/v1/admin/catalog/price-lists/{$priceList}/entries")->assertOk()->json('data'), 'variant_id'));
    }

    public function test_writes_outside_the_scoped_subtree_are_forbidden(): void
    {
        $this->scopedOperator(['category' => [$this->ids['apparel']]]);
        $base = '/api/v1/admin/catalog';

        $this->api('PATCH', "{$base}/products/{$this->ids['plush']}", ['name' => 'Taken', 'version' => 1], true)->assertForbidden();
        $this->api('DELETE', "{$base}/products/{$this->ids['plush']}?version=1", idempotent: true)->assertForbidden();
        $this->api('PATCH', "{$base}/products/{$this->ids['tee']}", ['category_id' => $this->ids['toys'], 'version' => 1], true)->assertForbidden();
        $this->api('POST', "{$base}/products", ['slug' => 'robot', 'name' => 'Robot', 'category_id' => $this->ids['toys'], 'origin_country' => 'ID'], true)->assertForbidden();
        $this->api('POST', "{$base}/products/{$this->ids['plush']}/variants", ['sku' => 'PLUSH-2', 'label' => 'Two', 'base_price' => 1], true)->assertForbidden();
        $this->api('PATCH', "{$base}/variants/{$this->ids['plush-variant']}", ['base_price' => 1, 'version' => 1], true)->assertForbidden();
        $this->api('DELETE', "{$base}/variants/{$this->ids['plush-variant']}?version=1", idempotent: true)->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $this->ids['plush'], 'name' => 'Plush', 'version' => 1, 'deleted_at' => null]);
        $this->assertDatabaseHas('product_variants', ['id' => $this->ids['plush-variant'], 'base_price' => 100000, 'deleted_at' => null]);

        $this->api('PATCH', "{$base}/products/{$this->ids['tee']}", ['name' => 'Tee v2', 'version' => 1], true)->assertOk()->assertJsonPath('data.version', 2);
        $this->api('PATCH', "{$base}/products/{$this->ids['tee']}", ['category_id' => $this->ids['hoodies'], 'version' => 2], true)->assertOk();
        $this->api('POST', "{$base}/products", ['slug' => 'zip-hoodie', 'name' => 'Zip Hoodie', 'category_id' => $this->ids['hoodies'], 'origin_country' => 'ID'], true)->assertCreated();
        $this->api('POST', "{$base}/products/{$this->ids['hoodie']}/variants", ['sku' => 'HOODIE-2', 'label' => 'Two', 'base_price' => 1], true)->assertCreated();

        $this->api('POST', "{$base}/categories", ['slug' => 'tees', 'name_key' => 'catalog.category.tees', 'parent_id' => $this->ids['apparel']], true)->assertCreated();
        $this->api('POST', "{$base}/categories", ['slug' => 'games', 'name_key' => 'catalog.category.games'], true)->assertForbidden();
        $this->api('PATCH', "{$base}/categories/{$this->ids['toys']}", ['name_key' => 'catalog.category.mine', 'version' => 1], true)->assertForbidden();
        $this->api('PATCH', "{$base}/categories/{$this->ids['hoodies']}", ['parent_id' => $this->ids['toys'], 'version' => 1], true)->assertForbidden();
        $this->api('POST', "{$base}/brands", ['slug' => 'kuma', 'name' => 'Kuma', 'country' => 'JP'], true)->assertForbidden();
        $this->api('PATCH', "{$base}/brands/{$this->ids['brand']}", ['name' => 'Renamed', 'version' => 1], true)->assertForbidden();
        $this->api('POST', "{$base}/search/reindex", [], true)->assertForbidden();
    }

    public function test_csv_import_rejects_rows_outside_the_scope_without_writing(): void
    {
        $this->scopedOperator(['category' => [$this->ids['apparel']]]);
        $csv = $this->csv([
            ['crew-tee', 'Crew Tee', 'apparel', '', '', 'active', 'ID', '', '', 'CREW-1', 'One', '100000', 'IDR', 'true', 'true'],
            ['robot', 'Robot', 'toys', '', '', 'active', 'ID', '', '', 'ROBOT-1', 'One', '100000', 'IDR', 'true', 'true'],
            ['plush', 'Plush Moved', 'apparel', '', '', 'active', 'ID', '', '', 'PLUSH-1', 'One', '100000', 'IDR', 'true', 'true'],
        ]);
        $errors = $this->api('POST', '/api/v1/admin/catalog/products/import', [], file: $csv)->assertUnprocessable()->json('errors');
        $this->assertEqualsCanonicalizing(['/rows/3/category_slug' => 'category_out_of_scope', '/rows/4/product_slug' => 'product_out_of_scope'],
            array_column($errors, 'code', 'pointer'));
        $this->assertDatabaseMissing('products', ['slug' => 'crew-tee']);
        $this->assertDatabaseHas('products', ['slug' => 'plush', 'category_id' => $this->ids['toys']]);

        $this->api('POST', '/api/v1/admin/catalog/products/import', [], file: $this->csv([
            ['crew-tee', 'Crew Tee', 'hoodies', '', '', 'active', 'ID', '', '', 'CREW-1', 'One', '100000', 'IDR', 'true', 'true'],
        ]))->assertCreated()->assertJsonPath('data.products_created', 1);
    }

    public function test_multiple_scopes_union_while_unscoped_roles_and_direct_grants_stay_global(): void
    {
        $this->scopedOperator(['category' => [$this->ids['hoodies'], $this->ids['toys']]]);
        $this->assertEqualsCanonicalizing(['hoodie', 'plush'], array_column($this->api('GET', '/api/v1/admin/catalog/products')->json('data'), 'slug'));

        $this->scopedOperator(['batch' => [12]]);
        $this->api('GET', '/api/v1/admin/catalog/products')->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "/api/v1/admin/catalog/products/{$this->ids['tee']}")->assertNotFound();

        $this->scopedOperator([]);
        $this->api('GET', '/api/v1/admin/catalog/products')->assertOk()->assertJsonCount(3, 'data');

        $direct = $this->scopedOperator(['category' => [$this->ids['toys']]]);
        $this->grant($direct, 'catalog.view');
        $this->api('GET', '/api/v1/admin/catalog/products')->assertOk()->assertJsonCount(3, 'data');
    }

    /** @param array<string, list<int>> $scopes kosong = role tanpa baris scope (global) */
    private function scopedOperator(array $scopes): User
    {
        $user = $this->loginAdmin();
        foreach (self::ALL as $name) {
            Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'admin'], ['group_name' => 'Catalog']);
        }
        $role = Role::query()->create(['name' => 'scoped-'.Str::lower(Str::random(6)), 'guard_name' => 'admin']);
        $role->givePermissionTo(self::ALL);
        $user->assignRole($role);
        foreach ($scopes as $type => $ids) {
            foreach ($ids as $id) {
                DB::table('role_scopes')->insert(['user_id' => $user->id, 'role_id' => $role->id, 'scope_type' => $type, 'scope_id' => $id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, ProductCsv::COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('catalog.csv', (string) stream_get_contents($handle));
    }
}
