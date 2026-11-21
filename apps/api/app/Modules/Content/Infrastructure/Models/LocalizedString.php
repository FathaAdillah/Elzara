<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Models;

use Database\Factories\LocalizedStringFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $value
 * @property int|null $plural_variant
 */
final class LocalizedString extends Model
{
    /** @use HasFactory<LocalizedStringFactory> */
    use HasFactory;

    protected $table = 'localizations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['plural_variant' => 'integer'];
    }

    protected static function newFactory(): LocalizedStringFactory
    {
        return LocalizedStringFactory::new();
    }
}
