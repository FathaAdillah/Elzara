<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

final class RevokePermissionFromUser extends AccessChange
{
    public function handle(ChangeUserAccess $access): void
    {
        $access->change($this, 'permission.revoke');
    }
}
