<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Reference\FeatureFlagSeeder;
use Database\Seeders\Reference\LocalizationSeeder;
use Database\Seeders\Reference\RolePermissionSeeder;
use Database\Seeders\Reference\SettingSeeder;
use Illuminate\Database\Seeder;

/** Insert-if-absent only: reruns never overwrite rows an operator has edited. */
final class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolePermissionSeeder::class, FeatureFlagSeeder::class, SettingSeeder::class, LocalizationSeeder::class]);
    }
}
