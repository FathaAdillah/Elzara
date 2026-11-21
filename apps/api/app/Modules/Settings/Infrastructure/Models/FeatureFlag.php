<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use Database\Factories\FeatureFlagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property bool $default_enabled
 * @property bool $requires_restart
 */
final class FeatureFlag extends Model
{
    /** @use HasFactory<FeatureFlagFactory> */
    use HasFactory;

    protected $table = 'feature_flags';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_enabled' => 'boolean', 'requires_restart' => 'boolean'];
    }

    protected static function newFactory(): FeatureFlagFactory
    {
        return FeatureFlagFactory::new();
    }
}
