<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $value
 * @property array<string, mixed> $default_value
 * @property bool $is_public
 */
final class Setting extends Model
{
    protected $table = 'settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'array', 'default_value' => 'array', 'is_public' => 'boolean'];
    }
}
