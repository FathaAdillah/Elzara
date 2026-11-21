<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application;

use App\Modules\Settings\Contracts\FeatureFlagQuery;
use App\Modules\Settings\Domain\MissingFeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;

final class FeatureFlagService implements FeatureFlagQuery
{
    public function enabled(string $code, int|string|null $subjectId = null): bool
    {
        $flag = FeatureFlag::query()->where('code', $code)->first() ?? throw new MissingFeatureFlag;
        $env = match (app()->environment()) {
            'production' => 'prod',
            'staging' => 'staging',
            default => 'local',
        };
        $override = FeatureFlagOverride::query()->where('flag_code', $code)->where('env', $env)->first();
        if (! ($override->enabled ?? $flag->default_enabled)) {
            return false;
        }

        return crc32((string) ($subjectId ?? 'anon')) % 100 < ($override->rollout_percent ?? 100);
    }
}
