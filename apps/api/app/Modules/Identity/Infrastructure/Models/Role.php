<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property bool $is_system
 */
final class Role extends \Spatie\Permission\Models\Role
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected string $guard_name = 'admin';

    public function guardName(): string
    {
        return 'admin';
    }

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }
}
