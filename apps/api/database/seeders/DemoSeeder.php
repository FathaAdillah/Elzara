<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoEnvironment;
use Database\Seeders\Demo\DemoMediaSeeder;
use Database\Seeders\Demo\DemoShippingSeeder;
use Illuminate\Database\Seeder;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DemoEnvironment::assertNotProduction();

        $this->call([ReferenceSeeder::class, DemoAccountSeeder::class, DemoMediaSeeder::class, DemoCatalogSeeder::class, DemoShippingSeeder::class]);
    }
}
