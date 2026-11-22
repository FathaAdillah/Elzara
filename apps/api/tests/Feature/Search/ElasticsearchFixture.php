<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Modules\Search\Application\ProductIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Elasticsearch nyata per uji: alias acak, dibuang di tearDown. Di CI ketiadaan ES adalah kegagalan, bukan skip. */
trait ElasticsearchFixture
{
    private function useElasticsearch(): void
    {
        $url = getenv('ELASTICSEARCH_TEST_URL') ?: '';
        if ($url === '') {
            getenv('CI') ? $this->fail('ELASTICSEARCH_TEST_URL wajib di CI.') : $this->markTestSkipped('ELASTICSEARCH_TEST_URL tidak diatur.');
        }
        try {
            Http::timeout(3)->get($url)->throw();
        } catch (Throwable $exception) {
            getenv('CI') ? $this->fail('Elasticsearch tidak terjangkau: '.$exception->getMessage()) : $this->markTestSkipped('Elasticsearch tidak terjangkau.');
        }
        config(['search.url' => $url, 'search.api_key' => null, 'search.products_alias' => 'elzara_test_'.bin2hex(random_bytes(6))]);
    }

    private function dropIndex(): void
    {
        if (str_starts_with((string) config('search.products_alias'), 'elzara_test_')) {
            app(ProductIndex::class)->drop();
        }
    }

    /** @return array<string, int> */
    private function seedSearchCatalog(string $prefix): array
    {
        $ids = [
            'apparel' => DB::table('categories')->insertGetId(['slug' => "{$prefix}apparel", 'name_key' => 'catalog.category.apparel']),
            'accessories' => DB::table('categories')->insertGetId(['slug' => "{$prefix}accessories", 'name_key' => 'catalog.category.accessories']),
            'yuki' => DB::table('brands')->insertGetId(['slug' => "{$prefix}yuki", 'name' => 'Yuki Studio']),
            'kuma' => DB::table('brands')->insertGetId(['slug' => "{$prefix}kuma", 'name' => 'Kuma Works']),
            'winter' => DB::table('collections')->insertGetId(['slug' => "{$prefix}winter", 'name_key' => 'catalog.collection.winter', 'kind' => 'tema']),
        ];
        $products = [
            'jaket' => ['Jaket Musim Dingin Aurora', 'yuki', 'apparel', 'ID', '{rts}', 'JKT-AUR-M', 450000],
            'kaos' => ['Kaos Grafis Neko', 'kuma', 'apparel', 'JP', '{po}', 'KAOS-NEKO', 150000],
            'ganci' => ['Gantungan Kunci Akrilik Shiro', 'kuma', 'accessories', 'JP', '{rts}', 'GK-SHIRO', 35000],
            'boneka' => ['Boneka Plush Kuma', 'yuki', 'accessories', 'ID', '{rts,po}', 'BNK-KUMA', 220000],
        ];
        foreach ($products as $key => [$name, $brand, $category, $country, $modes, $sku, $price]) {
            $id = DB::table('products')->insertGetId(['slug' => $prefix.$key, 'name' => $name, 'brand_id' => $ids[$brand], 'category_id' => $ids[$category],
                'origin_country' => $country, 'status' => 'active']);
            DB::update('UPDATE products SET fulfillment_modes = ?::varchar(32)[] WHERE id = ?', [$modes, $id]);
            DB::table('product_variants')->insert(['product_id' => $id, 'sku' => $prefix.$sku, 'label' => 'Default', 'base_price' => $price]);
            $ids[$key] = $id;
        }
        DB::table('product_collections')->insert(['product_id' => $ids['jaket'], 'collection_id' => $ids['winter']]);

        return $ids;
    }
}
