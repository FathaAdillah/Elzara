<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property bool $default_enabled
 * @property bool $requires_restart
 */
final class FeatureFlag extends Model
{
    protected $table = 'feature_flags';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_enabled' => 'boolean', 'requires_restart' => 'boolean'];
    }
}
