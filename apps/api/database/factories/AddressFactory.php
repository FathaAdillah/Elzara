<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\Address;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Address> */
final class AddressFactory extends Factory
{
    protected $model = Address::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Rumah',
            'recipient' => fake()->name(),
            'phone' => '+62812'.fake()->numerify('########'),
            'province' => 'DKI Jakarta',
            'city' => 'Jakarta Selatan',
            'district' => 'Kebayoran Baru',
            'village' => 'Senayan',
            'postal_code' => '12190',
            'address_line' => fake()->streetAddress(),
            'is_default' => false,
            'is_archived' => false,
        ];
    }

    public function default(): self
    {
        return $this->state(['is_default' => true]);
    }
}
