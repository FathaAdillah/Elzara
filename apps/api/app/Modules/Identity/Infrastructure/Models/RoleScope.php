<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $user_id
 * @property int $role_id
 * @property string|null $scope_type
 * @property int|null $scope_id
 */
final class RoleScope extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['scope_id' => 'integer', 'user_id' => 'integer', 'role_id' => 'integer'];
    }
}
