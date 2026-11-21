<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Database\Factories\PermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int $id
 * @property string $group_name
 */
final class Permission extends \Spatie\Permission\Models\Permission
{
    /** @use HasFactory<PermissionFactory> */
    use HasFactory;

    protected string $guard_name = 'admin';

    public function guardName(): string
    {
        return 'admin';
    }

    protected static function newFactory(): PermissionFactory
    {
        return PermissionFactory::new();
    }
}
