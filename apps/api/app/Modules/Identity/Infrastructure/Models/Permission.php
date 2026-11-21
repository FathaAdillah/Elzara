<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

/**
 * @property int $id
 * @property string $group_name
 */
final class Permission extends \Spatie\Permission\Models\Permission
{
    protected string $guard_name = 'admin';

    public function guardName(): string
    {
        return 'admin';
    }
}
