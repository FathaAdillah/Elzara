<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

/**
 * @property int $id
 * @property bool $is_system
 */
final class Role extends \Spatie\Permission\Models\Role
{
    protected string $guard_name = 'admin';

    public function guardName(): string
    {
        return 'admin';
    }
}
