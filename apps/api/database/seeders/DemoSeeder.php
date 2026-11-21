<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\Demo\DemoMediaSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder tidak boleh berjalan di environment production');
        }

        $this->call([ReferenceSeeder::class, DemoAccountSeeder::class, DemoMediaSeeder::class]);
    }
}
