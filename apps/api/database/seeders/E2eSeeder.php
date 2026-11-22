<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoEnvironment;
use Database\Seeders\Demo\DemoShippingSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class E2eSeeder extends Seeder
{
    public function run(): void
    {
        DemoEnvironment::assertNotProduction();
        $this->call([ReferenceSeeder::class, DemoAccountSeeder::class, DemoCatalogSeeder::class, DemoShippingSeeder::class]);

        $product = DB::table('products')->where('slug', 'e2e-request-charm')->value('id');
        if ($product === null) {
            $product = DB::table('products')->insertGetId([
                'slug' => 'e2e-request-charm', 'name' => 'E2E Request Charm',
                'category_id' => DB::table('categories')->where('slug', 'accessories')->value('id'),
                'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{request}',
                'description' => 'Request Order: penawaran dikonfirmasi oleh operator.', 'unit_weight_g' => 500,
            ]);
            DB::table('product_variants')->insert([
                'product_id' => $product, 'sku' => 'E2E-REQUEST-CHARM', 'label' => 'Standard', 'base_price' => 50000,
            ]);
        }
    }
}
