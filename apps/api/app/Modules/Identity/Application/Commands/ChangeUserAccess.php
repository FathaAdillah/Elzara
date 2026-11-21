<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Identity\Application\Queries\PermissionScopeResolver;
use App\Modules\Identity\Application\Queries\UserAccess;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

final class ChangeUserAccess
{
    public function __construct(private AuditRecorder $audit, private UserAccess $access, private PermissionScopeResolver $scopes) {}

    public function change(AccessChange $command, string $operation): void
    {
        if (trim($command->reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if ($command->actor->id === $command->target->id) {
            throw new HttpResponseException(ApiProblem::make('authorization', 'self-access-change', 'Akses ditolak', 403, 'RBAC_SELF_ACCESS_CHANGE',
                detail: "Operators can't change their own access")
                ->forRequest(request(), $command->requestId)->toResponse());
        }
        DB::transaction(function () use ($command, $operation): void {
            $target = User::query()->lockForUpdate()->findOrFail($command->target->id);
            if (! $target->is_operator) {
                throw ValidationException::withMessages(['user' => 'The target must be an operator.']);
            }
            $isRole = str_starts_with($operation, 'role.');
            $resource = $isRole
                ? Role::query()->where('name', $command->name)->where('guard_name', 'admin')->first()
                : Permission::query()->where('name', $command->name)->where('guard_name', 'admin')->first();
            if ($resource === null) {
                throw ValidationException::withMessages([$isRole ? 'role' : 'permission' => 'Unknown admin access name.']);
            }
            $type = $operation === 'role.assign' ? $command->scopeType : null;
            $id = $operation === 'role.assign' ? $command->scopeId : null;
            if (($type === null) !== ($id === null) || ($type !== null && (! in_array($type, ['batch', 'category', 'warehouse'], true) || $id < 1))) {
                throw ValidationException::withMessages(['scope_type' => 'Invalid scope pair.']);
            }
            $actor = $command->actor->fresh() ?? abort(403);
            if (! $actor->is_admin && in_array($operation, ['role.assign', 'permission.grant'], true)) {
                $permissions = $resource instanceof Role ? $resource->permissions->map(fn (\Spatie\Permission\Models\Permission $permission): string => $permission->name)->all() : [$resource->name];
                foreach ($permissions as $permission) {
                    $allowed = $this->scopes->allowed($permission, $actor);
                    abort_unless($allowed === null || ($type !== null && in_array($id, $allowed[$type] ?? [], true)), 403);
                }
            }
            $before = $this->access->forUser($target);
            if ($operation === 'permission.grant') {
                $target->givePermissionTo($resource->name);
            } elseif ($operation === 'permission.revoke') {
                $target->revokePermissionTo($resource->name);
            } elseif ($operation === 'role.revoke') {
                $target->removeRole($resource->name);
                DB::table('role_scopes')->where('user_id', $target->id)->where('role_id', $resource->id)->delete();
            } else {
                $target->assignRole($resource->name);
                DB::table('role_scopes')->insertOrIgnore(['user_id' => $target->id, 'role_id' => $resource->id, 'scope_type' => $type, 'scope_id' => $id]);
            }
            $this->audit->record($actor->id, 'rbac.'.$operation, 'users', $target->id, $before, $this->access->forUser($target),
                $command->reason, $command->requestId, $command->ip, $command->userAgent);
        });
        $command->target->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
