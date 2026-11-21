<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            /** @var array<string, list<string>> $actions */
            $actions = config('identity.roles.actions');
            $permissions = [];
            foreach ($actions as $prefix => $verbs) {
                foreach ($verbs as $verb) {
                    $name = $prefix.'.'.$verb;
                    $permission = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'admin'], ['group_name' => $prefix]);
                    if ($permission->getRawOriginal('created_at') === $permission->getRawOriginal('updated_at') && $permission->group_name !== $prefix) {
                        $permission->group_name = $prefix;
                        $permission->save();
                    }
                    $permissions[$name] = $permission;
                }
            }
            /** @var array<string, list<string>> $bundles */
            $bundles = config('identity.roles.bundles');
            foreach ($bundles as $name => $patterns) {
                $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'admin'], ['is_system' => true]);
                if ($role->getRawOriginal('created_at') !== $role->getRawOriginal('updated_at')) {
                    continue;
                }
                if (! $role->is_system) {
                    $role->is_system = true;
                    $role->save();
                }
                $desired = array_values(array_filter($permissions, fn (Permission $permission): bool => Str::is($patterns, $permission->name)));
                $ids = array_map(fn (Permission $permission): int => $permission->id, $desired);
                sort($ids);
                $current = $role->permissions()->pluck('permissions.id')->sort()->values()->all();
                if ($current !== $ids) {
                    $role->syncPermissions($desired);
                }
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
