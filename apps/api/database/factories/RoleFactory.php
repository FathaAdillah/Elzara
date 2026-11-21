<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Role> */
final class RoleFactory extends Factory
{
    protected $model = Role::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'role-'.Str::lower(Str::random(10)),
            'guard_name' => 'admin',
            'is_system' => false,
        ];
    }
}
