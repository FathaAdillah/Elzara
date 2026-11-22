<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DemoEnvironment::assertNotProduction();
        DB::table('categories')->insertOrIgnore(['slug' => 'collectibles', 'name_key' => 'Collectibles']);
        $root = DB::table('categories')->where('slug', 'collectibles')->value('id');
        DB::table('categories')->insertOrIgnore(['slug' => 'accessories', 'name_key' => 'Accessories', 'parent_id' => $root]);
        DB::table('categories')->insertOrIgnore(['slug' => 'home-goods', 'name_key' => 'Home goods', 'parent_id' => $root]);
        DB::table('brands')->insertOrIgnore(['slug' => 'elzara-studio', 'name' => 'Elzara Studio']);
        DB::table('brands')->insertOrIgnore(['slug' => 'nova-workshop', 'name' => 'Nova Workshop']);
        DB::table('warehouses')->insertOrIgnore(['code' => 'DEMO', 'name' => 'Demo warehouse', 'kind' => 'warehouse', 'country' => 'ID']);
        $warehouse = DB::table('warehouses')->where('code', 'DEMO')->value('id');
        $brand = DB::table('brands')->where('slug', 'elzara-studio')->value('id');
        $otherBrand = DB::table('brands')->where('slug', 'nova-workshop')->value('id');
        $category = DB::table('categories')->where('slug', 'accessories')->value('id');
        $homeCategory = DB::table('categories')->where('slug', 'home-goods')->value('id');
        /** @var list<array{slug: string, name: string, amount_minor: int, file: string}> $manifest */
        $manifest = require __DIR__.'/catalog-manifest.php';
        foreach ($manifest as $index => $item) {
            if (DB::table('products')->where('slug', $item['slug'])->exists()) {
                continue;
            }
            $product = DB::table('products')->insertGetId(['slug' => $item['slug'], 'name' => $item['name'], 'brand_id' => $index % 2 === 0 ? $brand : $otherBrand, 'category_id' => $index < 5 ? $category : $homeCategory, 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => $index === 1 ? '{po}' : '{rts}', 'description' => $item['name']."\nOriginal Elzara demo artwork."]);
            $values = [];
            foreach (['Warna' => ['Lavender', 'Mint'], 'Ukuran' => ['S', 'M']] as $name => $choices) {
                $option = DB::table('product_options')->insertGetId(['product_id' => $product, 'name' => $name, 'position' => count($values)]);
                foreach ($choices as $position => $choice) {
                    $values[$name][] = DB::table('product_option_values')->insertGetId(['option_id' => $option, 'value' => $choice, 'position' => $position]);
                }
            }
            foreach ($values['Warna'] as $color => $colorId) {
                foreach ($values['Ukuran'] as $size => $sizeId) {
                    $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'DEMO-'.$index.'-'.$color.'-'.$size, 'label' => ['Lavender', 'Mint'][$color].' / '.['S', 'M'][$size], 'base_price' => $item['amount_minor'] + $size * 10000]);
                    DB::table('variant_option_values')->insert([['variant_id' => $variant, 'option_value_id' => $colorId], ['variant_id' => $variant, 'option_value_id' => $sizeId]]);
                    DB::table('stock_items')->insert(['warehouse_id' => $warehouse, 'variant_id' => $variant, 'on_hand' => $color === 1 && $size === 1 ? 0 : 5]);
                }
            }
            $media = DB::table('media')->where('disk', 'product-media')->where('path', 'published/demo/'.$item['file'])->value('id');
            if ($media !== null) {
                DB::table('product_media')->insert(['product_id' => $product, 'media_id' => $media, 'role' => 'primary']);
            }
        }
    }
}
