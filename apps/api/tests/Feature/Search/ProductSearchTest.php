<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\Catalog\Application\ProductEvents;
use App\Modules\Search\Application\ProductIndex;
use App\Modules\Search\Application\ProductSearch;
use App\Modules\Search\Application\SyncProductSearchIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tanpa DatabaseTransactions: konsumen inbox dan dispatcher outbox menolak berjalan di dalam transaksi,
 * jadi data di-commit dengan prefiks acak lalu dibersihkan.
 */
final class ProductSearchTest extends TestCase
{
    use ElasticsearchFixture;

    private string $prefix = '';

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useElasticsearch();
        $this->prefix = 'st'.bin2hex(random_bytes(3)).'-';
        $this->ids = $this->seedSearchCatalog($this->prefix);
        Artisan::call('search:products:reindex');
    }

    protected function tearDown(): void
    {
        try {
            $this->dropIndex();
            $products = DB::table('products')->where('slug', 'like', $this->prefix.'%')->pluck('id');
            DB::table('inbox_events')->where('consumer', SyncProductSearchIndex::CONSUMER)->delete();
            DB::table('outbox_events')->where('aggregate_type', 'product')->whereIn('aggregate_id', $products)->delete();
            DB::table('products')->whereIn('id', $products)->delete();
            foreach (['collections', 'brands', 'categories'] as $table) {
                DB::table($table)->where('slug', 'like', $this->prefix.'%')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_matching_typo_tolerance_prefix_sku_and_curated_synonyms(): void
    {
        $this->assertHits(['jaket'], ['q' => 'aurora']);
        $this->assertHits(['jaket'], ['q' => 'jakte musim'], 'fuzziness AUTO menoleransi transposisi');
        $this->assertHits(['boneka'], ['q' => 'bonekka']);
        $this->assertHits(['ganci'], ['q' => 'gant'], 'analyzer prefiks edge n-gram');
        $this->assertHits(['ganci'], ['q' => 'akri']);
        $this->assertHits(['ganci'], ['q' => $this->prefix.'GK-SHIRO']);
        $this->assertHits(['jaket'], ['q' => 'jacket'], 'sinonim jaket ⇄ jacket');
        $this->assertHits(['kaos'], ['q' => 't-shirt']);
        $this->assertHits(['ganci'], ['q' => 'keychain'], 'sinonim multi-kata gantungan kunci');
        $this->assertHits(['boneka'], ['q' => 'plushie']);
        $this->assertHits([], ['q' => 'sepatu']);
    }

    public function test_category_scope_limits_hits_and_facet_counts(): void
    {
        $result = app(ProductSearch::class)->search([], [$this->ids['accessories']]);
        $this->assertEqualsCanonicalizing([$this->prefix.'ganci', $this->prefix.'boneka'], array_column($result['data'], 'slug'));
        $this->assertSame([['value' => $this->prefix.'accessories', 'count' => 2]], $result['facets']['category']);
        $this->assertEqualsCanonicalizing([['value' => $this->prefix.'kuma', 'count' => 1], ['value' => $this->prefix.'yuki', 'count' => 1]], $result['facets']['brand']);
        $this->assertSame(2, $result['meta']['total']);
        $this->assertSame([], app(ProductSearch::class)->search(['q' => 'aurora'], [$this->ids['accessories']])['data']);
        $this->assertSame(0, app(ProductSearch::class)->search([], [])['meta']['total']);
    }

    public function test_keyword_filters_price_range_facets_and_cursor(): void
    {
        $this->assertHits(['kaos', 'ganci'], ['brand' => [$this->prefix.'kuma']]);
        $this->assertHits(['boneka'], ['category' => [$this->prefix.'accessories'], 'origin_country' => ['ID']]);
        $this->assertHits(['jaket'], ['collections' => [$this->prefix.'winter']]);
        $this->assertHits(['kaos', 'boneka'], ['fulfillment_modes' => ['po']]);
        $this->assertHits(['kaos', 'boneka'], ['price_min' => 100000, 'price_max' => 300000]);

        $result = app(ProductSearch::class)->search(['brand' => [$this->prefix.'kuma']]);
        $this->assertEqualsCanonicalizing([['value' => $this->prefix.'kuma', 'count' => 2], ['value' => $this->prefix.'yuki', 'count' => 2]], $result['facets']['brand'],
            'facet milik filter aktif tetap menampilkan pilihan lain (post_filter)');
        $this->assertEqualsCanonicalizing([['value' => $this->prefix.'accessories', 'count' => 1], ['value' => $this->prefix.'apparel', 'count' => 1]], $result['facets']['category']);
        $this->assertSame(2, $result['meta']['total']);

        $first = app(ProductSearch::class)->search(['limit' => 3]);
        $this->assertTrue($first['meta']['has_more']);
        $second = app(ProductSearch::class)->search(['limit' => 3, 'cursor' => $first['meta']['next_cursor']]);
        $this->assertFalse($second['meta']['has_more']);
        $this->assertCount(4, array_unique([...array_column($first['data'], 'id'), ...array_column($second['data'], 'id')]));

        try {
            app(ProductSearch::class)->search(['limit' => 3, 'q' => 'kaos', 'cursor' => $first['meta']['next_cursor']]);
            $this->fail('Cursor dari pencarian lain harus ditolak.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_reindex_rebuilds_from_postgres_and_swaps_the_alias(): void
    {
        $index = app(ProductIndex::class);
        $before = $index->indicesBehindAlias();
        $this->assertCount(1, $before);
        DB::table('products')->where('id', $this->ids['kaos'])->update(['name' => 'Kaos Edisi Reindex']);

        $this->assertSame(0, Artisan::call('search:products:reindex'));
        $this->assertMatchesRegularExpression('/index=\S+ documents=4/', Artisan::output());
        $after = $index->indicesBehindAlias();
        $this->assertCount(1, $after);
        $this->assertNotSame($before, $after);
        $this->assertHits(['kaos'], ['q' => 'reindex']);
    }

    public function test_product_update_reaches_the_index_through_outbox_within_sixty_seconds(): void
    {
        config(['queue.default' => 'sync']);
        $started = microtime(true);
        DB::transaction(function (): void {
            DB::table('products')->where('id', $this->ids['kaos'])->update(['name' => 'Kaos Grafis Neko Edisi Salju', 'version' => 2]);
            app(ProductEvents::class)->updated($this->ids['kaos'], ['name'], 1);
            DB::table('products')->where('id', $this->ids['ganci'])->update(['deleted_at' => Carbon::now()]);
            app(ProductEvents::class)->updated($this->ids['ganci'], ['deleted_at'], 1);
        });
        $this->assertSame(0, Artisan::call('platform:outbox:dispatch', ['--queue' => 'imports', '--limit' => 200]));
        app(ProductIndex::class)->refresh();
        $this->assertHits(['kaos'], ['q' => 'salju']);
        $this->assertHits([], ['q' => 'shiro'], 'produk terhapus keluar dari indeks');
        $this->assertLessThan(60, microtime(true) - $started);
        $this->assertSame(2, DB::table('inbox_events')->where('consumer', SyncProductSearchIndex::CONSUMER)->where('status', 'processed')->count());

        // Event basi (occurred_at lama) tidak boleh menimpa dokumen yang lebih baru: versi eksternal menolak.
        DB::table('products')->where('id', $this->ids['kaos'])->update(['name' => 'Nama Basi']);
        app(SyncProductSearchIndex::class)->handle(['event_id' => '0f2b7a6e-3c1d-4d8e-9f00-000000000001', 'aggregate_id' => $this->ids['kaos'],
            'payload' => ['occurred_at' => Carbon::now()->subHour()->format('Y-m-d\TH:i:s.uP')]]);
        app(ProductIndex::class)->refresh();
        $this->assertHits(['kaos'], ['q' => 'salju']);
        $this->assertHits([], ['q' => 'basi']);
    }

    /** @param array<string, mixed> $params */
    private function assertHits(array $keys, array $params, string $message = ''): void
    {
        $ids = array_column(app(ProductSearch::class)->search($params)['data'], 'id');
        $expected = array_map(fn (string $key): int => $this->ids[$key], $keys);
        $this->assertEqualsCanonicalizing($expected, $ids, $message.' '.json_encode($params));
    }
}
