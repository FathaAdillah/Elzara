<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Permission> */
final class PermissionFactory extends Factory
{
    protected $model = Permission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'test.'.Str::lower(Str::random(10)),
            'guard_name' => 'admin',
            'group_name' => 'test',
        ];
    }
}
