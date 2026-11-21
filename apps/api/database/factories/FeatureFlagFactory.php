<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FeatureFlag> */
final class FeatureFlagFactory extends Factory
{
    protected $model = FeatureFlag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'test_'.Str::lower(Str::random(10)).'.enabled',
            'description' => fake()->sentence(),
            'default_enabled' => false,
            'requires_restart' => false,
        ];
    }

    public function enabled(): self
    {
        return $this->state(['default_enabled' => true]);
    }
}
