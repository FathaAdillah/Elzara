<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Queries;

use App\Modules\Settings\Contracts\FeatureFlagQuery;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;

final class ListFeatureFlags
{
    public const PUBLIC_CODES = ['checkout.enabled', 'drops.enabled', 'reviews.enabled', 'guest_checkout.enabled', 'loyalty.enabled', 'marketplace_purchase.enabled'];

    public function __construct(private FeatureFlagQuery $flags) {}

    /** @return list<array{code: string, enabled: bool}> */
    public function publicFlags(int|string|null $subjectId = null): array
    {
        return array_map(fn (string $code): array => ['code' => $code, 'enabled' => $this->flags->enabled($code, $subjectId)], self::PUBLIC_CODES);
    }

    /** @return list<array<string, mixed>> */
    public function adminFlags(): array
    {
        return array_values(FeatureFlag::query()->orderBy('code')->get()->map(function (FeatureFlag $flag): array {
            return $flag->toArray() + ['overrides' => FeatureFlagOverride::query()->where('flag_code', $flag->code)->orderBy('env')->get()->toArray()];
        })->all());
    }
}
