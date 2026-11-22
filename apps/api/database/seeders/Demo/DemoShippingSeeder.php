<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class DemoShippingSeeder extends Seeder
{
    public function run(): void
    {
        DemoEnvironment::assertNotProduction();
        $creator = DB::table('users')->where('email', DemoAccountSeeder::OPERATOR_EMAIL)->value('id');
        foreach (['jne' => ['REG', 'JNE Regular', 18000], 'sicepat' => ['REG', 'SiCepat Regular', 16000]] as $courier => [$service,$name,$amount]) {
            DB::table('courier_services')->insertOrIgnore(['courier_code' => $courier, 'service_code' => $service, 'name' => $name]);
            if (! DB::table('shipping_tariff_rules')->where('courier_code', $courier)->where('service_code', $service)->where('package_tier', 'paket_besar')->exists()) {
                DB::table('shipping_tariff_rules')->insert(['courier_code' => $courier, 'service_code' => $service, 'package_tier' => 'paket_besar', 'weight_from_g' => 0, 'base_amount' => $amount, 'per_500g_amount' => 3000, 'effective_from' => '2020-01-01', 'created_by' => $creator]);
            }
        }
        /** @var list<array{slug:string}> $manifest */
        $manifest = require __DIR__.'/catalog-manifest.php';
        DB::table('products')->whereIn('slug', array_column($manifest, 'slug'))->whereNull('unit_weight_g')->update(['unit_weight_g' => 500]);
    }
}
