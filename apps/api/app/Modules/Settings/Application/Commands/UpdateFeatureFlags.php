<?php

declare(strict_types=1);

namespace App\Modules\Settings\Application\Commands;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Settings\Domain\MissingFeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Modules\Settings\Infrastructure\Models\FeatureFlagOverride;
use Illuminate\Support\Facades\DB;

final class UpdateFeatureFlags
{
    /** @param list<array{code: string, default_enabled: bool, env?: string, enabled?: bool, rollout_percent?: int}> $flags */
    public function __construct(public readonly int $actorId, public readonly array $flags, public readonly string $reason, public readonly string $requestId, public readonly ?string $ip, public readonly string $userAgent) {}

    public function handle(AuditRecorder $audit): void
    {
        DB::transaction(function () use ($audit): void {
            foreach ($this->flags as $row) {
                $flag = FeatureFlag::query()->where('code', $row['code'])->lockForUpdate()->first() ?? throw new MissingFeatureFlag;
                $override = isset($row['env']) ? FeatureFlagOverride::query()->where('flag_code', $row['code'])->where('env', $row['env'])->first() : null;
                $before = ['default_enabled' => $flag->default_enabled, 'override' => $override?->toArray()];
                $flag->update(['default_enabled' => $row['default_enabled'], 'updated_by' => $this->actorId]);
                if (isset($row['env'], $row['enabled'])) {
                    if ($override === null) {
                        $override = new FeatureFlagOverride(['flag_code' => $row['code'], 'env' => $row['env']]);
                    }
                    $override->fill(['enabled' => $row['enabled'], 'rollout_percent' => $row['rollout_percent'] ?? 100, 'updated_by' => $this->actorId])->save();
                }
                $audit->record($this->actorId, 'setting.update', 'feature_flags', $flag->id, $before,
                    ['default_enabled' => $flag->default_enabled, 'override' => $override?->toArray()],
                    $this->reason, $this->requestId, $this->ip, $this->userAgent);
            }
        });
    }
}
