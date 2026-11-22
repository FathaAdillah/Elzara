<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Queries;

use App\Modules\Identity\Contracts\RowScopes;
use App\Modules\Identity\Infrastructure\Models\User;

final class RoleRowScopes implements RowScopes
{
    public function __construct(private readonly PermissionScopeResolver $resolver) {}

    public function allowedIds(int $userId, string $ability, string $scopeType): ?array
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            return [];
        }
        if ($user->is_admin) {
            return null;
        }
        $allowed = $this->resolver->allowed($ability, $user);

        return $allowed === null ? null : ($allowed[$scopeType] ?? []);
    }
}
