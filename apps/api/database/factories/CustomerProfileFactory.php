<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerProfile> */
final class CustomerProfileFactory extends Factory
{
    protected $model = CustomerProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'display_name' => fake()->firstName(),
            'gender' => 'undisclosed',
            'external_ids' => [],
        ];
    }
}
