<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeatureFlagOverride> */
final class FeatureFlagOverrideFactory extends Factory
{
    protected $model = FeatureFlagOverride::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'flag_code' => fn (): string => FeatureFlag::factory()->create()->code,
            'env' => 'local',
            'enabled' => true,
            'rollout_percent' => 100,
            'updated_by' => User::factory()->operator(),
        ];
    }
}
