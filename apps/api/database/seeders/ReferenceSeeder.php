<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Reference\RolePermissionSeeder;
use Illuminate\Database\Seeder;

final class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
    }
}
