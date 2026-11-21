<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 * @property int|null $plural_variant
 */
final class LocalizedString extends Model
{
    protected $table = 'localizations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['plural_variant' => 'integer'];
    }
}
