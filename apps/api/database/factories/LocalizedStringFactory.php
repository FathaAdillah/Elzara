<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Content\Infrastructure\Models\LocalizedString;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LocalizedString> */
final class LocalizedStringFactory extends Factory
{
    protected $model = LocalizedString::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'test.'.Str::lower(Str::random(12)),
            'group_name' => 'shop',
            'locale' => fake()->randomElement(['id', 'en']),
            'value' => fake()->sentence(),
        ];
    }
}
