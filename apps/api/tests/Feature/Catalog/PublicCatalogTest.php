<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PublicCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private int $category;

    private int $child;

    private int $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->category = DB::table('categories')->insertGetId(['slug' => 'figures', 'name_key' => 'Figures']);
        $this->child = DB::table('categories')->insertGetId(['slug' => 'model-kits', 'name_key' => 'Model kits', 'parent_id' => $this->category]);
        $this->warehouse = DB::table('warehouses')->insertGetId(['code' => 'TEST', 'name' => 'Test', 'kind' => 'warehouse']);
    }

    private function product(string $slug = 'gundam', string $mode = '{rts}', int $price = 100000, int $stock = 3): array
    {
        $product = DB::table('products')->insertGetId(['slug' => $slug, 'name' => ucfirst($slug), 'category_id' => $this->child, 'status' => 'active', 'origin_country' => 'JP', 'fulfillment_modes' => $mode, 'description' => "<script>alert(1)</script>\nSafe text"]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => $slug.'-1', 'label' => 'Standard', 'base_price' => $price]);
        DB::table('stock_items')->insert(['warehouse_id' => $this->warehouse, 'variant_id' => $variant, 'on_hand' => $stock]);

        return [$product, $variant];
    }

    public function test_typo_search_matches_product_brand_and_series(): void
    {
        [$gundam] = $this->product();
        $this->product('nendoroid');
        $brand = DB::table('brands')->insertGetId(['slug' => 'bandai', 'name' => 'Bandai']);
        DB::table('products')->where('id', $gundam)->update(['brand_id' => $brand, 'series' => 'Unicorn']);
        foreach (['gundum' => 'gundam', 'nendoriid' => 'nendoroid', 'bandia' => 'gundam', 'unicrn' => 'gundam'] as $q => $slug) {
            $this->getJson('/api/v1/products?q='.$q.'&sort=relevance')->assertOk()->assertJsonPath('data.0.slug', $slug);
        }
        $this->getJson('/api/v1/products?q=xxxxxxxxx')->assertOk()->assertJsonPath('data', []);
    }

    public function test_filters_and_category_descendants(): void
    {
        $this->product();
        $this->product('nendoroid', '{po}', 200000, 0);
        foreach (['kind=pre_order' => 'nendoroid', 'price_min=150000&price_max=250000' => 'nendoroid', 'in_stock=true' => 'gundam', 'in_stock=false' => 'nendoroid'] as $query => $slug) {
            $this->getJson('/api/v1/products?'.$query)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', $slug);
        }
        $this->getJson('/api/v1/products?category=figures')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/products?price_max=50000')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonFragment(['slug' => 'model-kits', 'name_key' => 'Model kits', 'parent' => 'figures', 'position' => 0]);
    }

    public function test_cursor_paging_has_no_duplicates_for_each_sort_and_binds_filters(): void
    {
        foreach (range(1, 5) as $index) {
            $this->product('gundam-'.$index, price: 100000 + ($index % 2));
        }
        foreach (['newest', 'price_asc', 'price_desc', 'relevance'] as $sort) {
            $url = '/api/v1/products?limit=2&q=gundam&sort='.$sort;
            $ids = [];
            $cursor = null;
            do {
                $page = $this->getJson($url.($cursor ? '&cursor='.$cursor : ''))->assertOk()->json();
                $ids = [...$ids, ...array_column($page['data'], 'id')];
                $cursor = $page['meta']['next_cursor'];
            } while ($cursor !== null);
            $this->assertCount(5, array_unique($ids));
            $this->assertCount(5, $ids);
        }
        $cursor = $this->getJson('/api/v1/products?limit=1')->json('meta.next_cursor');
        $this->getJson('/api/v1/products?kind=pre_order&cursor='.$cursor)->assertStatus(400);
    }

    public function test_non_public_products_and_variants_are_hidden_and_pdp_returns_problem(): void
    {
        foreach (['draft', 'archived', 'deleted', 'inactive-variant', 'deleted-variant'] as $case) {
            [$product, $variant] = $this->product($case);
            if (in_array($case, ['draft', 'archived'], true)) {
                DB::table('products')->where('id', $product)->update(['status' => $case]);
            } elseif ($case === 'deleted') {
                DB::table('products')->where('id', $product)->update(['deleted_at' => now()]);
            } else {
                DB::table('product_variants')->where('id', $variant)->update($case === 'inactive-variant' ? ['is_active' => false] : ['deleted_at' => now()]);
            }
            $this->getJson('/api/v1/products/'.$case)->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'NOT_FOUND');
        }
        $this->getJson('/api/v1/products/missing')->assertNotFound();
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data', []);
    }

    public function test_pdp_options_attributes_html_and_immediate_price_stock_sync(): void
    {
        [$product, $variant] = $this->product();
        foreach (['Warna' => 'Mint', 'Ukuran' => 'M'] as $position => $value) {
            $option = DB::table('product_options')->insertGetId(['product_id' => $product, 'name' => $position, 'position' => $position === 'Warna' ? 0 : 1]);
            $optionValue = DB::table('product_option_values')->insertGetId(['option_id' => $option, 'value' => $value]);
            DB::table('variant_option_values')->insert(['variant_id' => $variant, 'option_value_id' => $optionValue]);
        }
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonPath('variants.0.attributes.Warna', 'Mint')->assertJsonPath('options.0.name', 'Warna')->assertJsonPath('options.1.values.0', 'M')->assertJsonPath('description_html', '<p>&lt;script&gt;alert(1)&lt;/script&gt;<br>Safe text</p>');
        DB::table('stock_items')->where('variant_id', $variant)->update(['reserved' => 2]);
        DB::table('product_variants')->where('id', $variant)->update(['base_price' => 150000]);
        $this->getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.price.amount_minor', 150000)->assertJsonPath('data.0.available', 1);
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonPath('variants.0.price.amount_minor', 150000)->assertJsonPath('variants.0.available', 1);
        $list = DB::table('price_lists')->insertGetId(['code' => 'RETAIL', 'name' => 'Retail', 'valid_from' => now()->subDay(), 'valid_to' => now()->addDay()]);
        DB::table('price_list_entries')->insert(['price_list_id' => $list, 'variant_id' => $variant, 'price' => 125000]);
        $this->getJson('/api/v1/products?price_max=130000')->assertOk()->assertJsonPath('data.0.price.amount_minor', 125000);
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonPath('variants.0.price.amount_minor', 125000);
        DB::table('price_lists')->where('id', $list)->update(['valid_to' => now()->subHour(), 'valid_from' => now()->subDay()]);
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonPath('price.amount_minor', 150000);
    }

    public function test_strict_validation_and_headers(): void
    {
        foreach (['unknown=1', 'limit=0', 'limit=101', 'q[]=gundam', 'kind=po', 'price_min=-1', 'price_min=20&price_max=10', 'in_stock=yes', 'sort=relevance', 'brand[]=bad'] as $query) {
            $this->getJson('/api/v1/products?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/products')->assertOk()->assertHeader('X-Request-Id')->assertHeader('X-RateLimit-Policy', 'public')->assertHeader('Cache-Control', 'max-age=30, public');
    }

    public function test_media_priority_live_aggregates_and_brand_collection_filters(): void
    {
        [$product] = $this->product(mode: '{request,po,rts}');
        $brand = DB::table('brands')->insertGetId(['slug' => 'bandai', 'name' => 'Bandai']);
        DB::table('products')->where('id', $product)->update(['brand_id' => $brand]);
        $collection = DB::table('collections')->insertGetId(['slug' => 'unicorn', 'name_key' => 'Unicorn']);
        DB::table('product_collections')->insert(['product_id' => $product, 'collection_id' => $collection]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'second', 'label' => 'Second', 'base_price' => 50000]);
        DB::table('stock_items')->insert(['warehouse_id' => $this->warehouse, 'variant_id' => $variant, 'on_hand' => 0, 'in_transit' => 2, 'reserved' => 2]);
        DB::table('product_variants')->insert(['product_id' => $product, 'sku' => 'hidden', 'label' => 'Hidden', 'base_price' => 1, 'is_active' => false]);
        foreach (['gallery', 'primary'] as $position => $role) {
            $media = DB::table('media')->insertGetId(['model_type' => 'product', 'model_id' => $product, 'kind' => 'image', 'disk' => 'product-media', 'path' => 'published/'.$role.' image.jpg', 'alt_key' => $role]);
            DB::table('product_media')->insert(['product_id' => $product, 'media_id' => $media, 'position' => $position, 'role' => $role]);
        }
        $this->getJson('/api/v1/products?brand=bandai&collection=unicorn')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.price.amount_minor', 50000)->assertJsonPath('data.0.available', 3)->assertJsonPath('data.0.kind', 'rts')->assertJsonPath('data.0.kinds', ['rts', 'pre_order', 'request_order'])->assertJsonPath('data.0.primary_media.alt', 'primary');
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonCount(2, 'variants')->assertJsonPath('media.0.alt', 'gallery')->assertJsonPath('collections.0.slug', 'unicorn');
        DB::table('product_media')->where('product_id', $product)->update(['role' => 'gallery']);
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonPath('primary_media.alt', 'gallery');
        $this->getJson('/api/v1/products?collection=missing')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/products?brand=missing')->assertOk()->assertJsonPath('data', []);
        DB::table('localizations')->insert(['key' => 'Model kits', 'group_name' => 'shop', 'locale' => 'id', 'value' => 'Model rakitan']);
        Cache::flush();
        $this->getJson('/api/v1/products/gundam')->assertOk()->assertJsonPath('category.name', 'Model rakitan');
        DB::table('categories')->where('id', $this->category)->update(['is_active' => false]);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/categories?unexpected=1')->assertUnprocessable();
        $this->getJson('/api/v1/products/gundam?unexpected=1')->assertUnprocessable();
    }
}
