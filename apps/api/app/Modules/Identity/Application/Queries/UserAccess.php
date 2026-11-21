<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\RoleScope;
use App\Modules\Identity\Infrastructure\Models\User;

final class UserAccess
{
    /** @return array<string, mixed> */
    public function forUser(User $user): array
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');
        $scopes = RoleScope::query()->where('user_id', $user->id)->orderBy('scope_type')->orderBy('scope_id')->get();

        return [
            'roles' => Role::query()->whereIn('id', $user->roles()->select('roles.id'))->where('guard_name', 'admin')->orderBy('name')->get()->map(fn (Role $role): array => [
                'role' => $role->name,
                'scopes' => $scopes->where('role_id', $role->id)->map(fn (RoleScope $scope): array => [
                    'scope_type' => $scope->scope_type,
                    'scope_id' => $scope->scope_id,
                ])->values()->all(),
            ])->all(),
            'direct_permissions' => $user->getDirectPermissions()->where('guard_name', 'admin')->pluck('name')->sort()->values()->all(),
            'permissions' => $user->getAllPermissions()->where('guard_name', 'admin')->pluck('name')->unique()->sort()->values()->all(),
            'is_admin' => $user->is_admin,
        ];
    }
}
