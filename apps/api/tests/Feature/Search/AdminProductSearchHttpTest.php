<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\Search\Application\ProductIndexer;
use App\Modules\Search\Application\ReindexProductSearch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Catalog\AdminCatalogSession;
use Tests\TestCase;

final class AdminProductSearchHttpTest extends TestCase
{
    use AdminCatalogSession;
    use DatabaseTransactions;
    use ElasticsearchFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useElasticsearch();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        $this->dropIndex();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    public function test_admin_search_endpoint_filters_and_returns_facets(): void
    {
        $ids = $this->seedSearchCatalog('http-');
        app(ProductIndexer::class)->reindexAll(1);
        $this->api('GET', '/api/v1/admin/catalog/search?q=jacket')->assertUnauthorized();
        $user = $this->loginAdmin();
        $this->api('GET', '/api/v1/admin/catalog/search?q=jacket')->assertForbidden();
        $this->grant($user, 'catalog.view');
        $this->api('GET', '/api/v1/admin/catalog/search?q=jacket')->assertOk()->assertJsonPath('data.0.id', $ids['jaket'])->assertJsonPath('meta.total', 1);
        $this->api('GET', '/api/v1/admin/catalog/search?brand=http-kuma,http-yuki&category=http-accessories&limit=1')->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.has_more', true)->assertJsonCount(1, 'data')->assertJsonStructure(['facets' => ['brand', 'category', 'collections', 'origin_country', 'fulfillment_modes', 'status']]);
        $this->api('GET', '/api/v1/admin/catalog/search?limit=500')->assertUnprocessable();
        $this->api('GET', '/api/v1/admin/catalog/search?cursor=forged')->assertUnprocessable();
    }

    public function test_reindex_endpoint_queues_a_rebuild_on_imports(): void
    {
        Queue::fake();
        $user = $this->loginAdmin('catalog.view');
        $this->api('POST', '/api/v1/admin/catalog/search/reindex', idempotent: true)->assertForbidden();
        $this->grant($user, 'catalog.update');
        $this->api('POST', '/api/v1/admin/catalog/search/reindex', idempotent: true, csrf: false)->assertStatus(419);
        $this->api('POST', '/api/v1/admin/catalog/search/reindex', idempotent: true)->assertStatus(202)->assertJsonPath('data.queue', 'imports');
        Queue::assertPushedOn('imports', ReindexProductSearch::class);
    }
}
