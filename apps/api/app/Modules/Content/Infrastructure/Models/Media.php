<?php

declare(strict_types=1);

namespace App\Modules\Content\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $model_id
 * @property string $model_type
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property string|null $sha256
 * @property string|null $object_version
 * @property Carbon $created_at
 */
final class Media extends Model
{
    protected $table = 'media';

    protected $guarded = [];
}
