<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\RoleScope;
use App\Modules\Identity\Infrastructure\Models\User;

final class PermissionScopeResolver
{
    /** @return array<string, list<int>>|null */
    public function allowed(string $ability, User $user): ?array
    {
        $user->unsetRelation('permissions')->unsetRelation('roles');
        if (! $user->checkPermissionTo($ability, 'admin')) {
            return [];
        }
        if ($user->hasDirectPermission($ability)) {
            return null;
        }
        $allowed = [];
        foreach (Role::query()->whereIn('id', $user->roles()->select('roles.id'))->where('guard_name', 'admin')->with('permissions')->get() as $role) {
            if (! $role->permissions->contains('name', $ability)) {
                continue;
            }
            $scopes = RoleScope::query()->where('user_id', $user->id)->where('role_id', $role->id)->get();
            if ($scopes->isEmpty() || $scopes->contains(fn (RoleScope $scope): bool => $scope->scope_type === null)) {
                return null;
            }
            foreach ($scopes as $scope) {
                if ($scope->scope_type !== null && $scope->scope_id !== null) {
                    $allowed[$scope->scope_type][] = $scope->scope_id;
                }
            }
        }
        foreach ($allowed as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        ksort($allowed);

        return $allowed;
    }
}
