<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $flag_code
 * @property string $env
 * @property bool $enabled
 * @property int $rollout_percent
 */
final class FeatureFlagOverride extends Model
{
    protected $table = 'feature_flag_overrides';

    protected $guarded = [];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'flag_code';

    protected $keyType = 'string';

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('flag_code', $this->getRawOriginal('flag_code') ?? $this->flag_code)
            ->where('env', $this->getRawOriginal('env') ?? $this->env);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'rollout_percent' => 'integer'];
    }
}
