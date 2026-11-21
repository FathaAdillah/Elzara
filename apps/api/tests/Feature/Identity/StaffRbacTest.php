<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Queries\PermissionScopeResolver;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Database\Seeders\Reference\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class StaffRbacTest extends TestCase
{
    use DatabaseTransactions;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        (new RolePermissionSeeder)->run();
        FeatureFlag::create(['code' => 'checkout.enabled', 'description' => 'Checkout', 'default_enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    public function test_unknown_permissions_fall_through_to_policy_abilities(): void
    {
        $operator = $this->operator();
        Auth::shouldUse('admin');
        Auth::guard('admin')->setUser($operator);
        $this->assertFalse(Gate::forUser($operator)->allows('some-policy-ability'));
        Gate::define('some-policy-ability', fn (User $user): bool => $user->id === $operator->id);
        $this->assertTrue(Gate::forUser($operator)->allows('some-policy-ability'));
    }

    public function test_seeder_is_a_zero_write_second_run_and_bundles_match_config(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $counts = [Permission::count(), Role::count(), DB::table('role_has_permissions')->count()];
        (new RolePermissionSeeder)->run();
        $this->assertSame([], $writes);
        $this->assertSame($counts, [Permission::count(), Role::count(), DB::table('role_has_permissions')->count()]);
        $this->assertSame(10, Role::count());
        $all = Permission::pluck('name')->all();
        foreach (config('identity.roles.bundles') as $name => $patterns) {
            $expected = array_values(array_filter($all, fn ($permission) => Str::is($patterns, $permission)));
            sort($expected);
            $this->assertSame($expected, Role::findByName($name, 'admin')->permissions->pluck('name')->sort()->values()->all());
        }
        foreach (['review.create', 'user.delete', 'order.delete', 'billing.delete', 'payment.delete', 'marketplace.delete', 'audit.create', 'audit.update', 'audit.delete', 'report.create', 'report.update', 'report.delete'] as $forbidden) {
            $this->assertNotContains($forbidden, $all);
        }
    }

    public function test_seeder_preserves_admin_edited_rows_and_bundles(): void
    {
        $role = Role::findByName('finance', 'admin');
        $role->syncPermissions(['order.view']);
        DB::table('roles')->where('id', $role->id)->update(['description' => 'Edited', 'updated_at' => now()->addSecond()]);
        DB::table('permissions')->where('name', 'order.view')->update(['group_name' => 'custom', 'updated_at' => now()->addSecond()]);
        (new RolePermissionSeeder)->run();
        $this->assertSame(['order.view'], $role->fresh()->permissions->pluck('name')->all());
        $this->assertDatabaseHas('permissions', ['name' => 'order.view', 'group_name' => 'custom']);
    }

    public function test_direct_grants_and_revocation_take_effect_in_the_same_session(): void
    {
        $user = $this->operator();
        $user->givePermissionTo('setting.view');
        $this->login($user);
        $this->send('GET', '/api/v1/admin/feature-flags')->assertOk();
        $payload = ['flags' => [['code' => 'checkout.enabled', 'default_enabled' => false]], 'reason' => 'Maintenance'];
        $this->send('PATCH', '/api/v1/admin/feature-flags', $payload)->assertForbidden();
        $user->givePermissionTo('setting.update');
        $this->send('PATCH', '/api/v1/admin/feature-flags', $payload)->assertOk();
        $user->revokePermissionTo('setting.update');
        $this->send('PATCH', '/api/v1/admin/feature-flags', $payload)->assertForbidden();
        $this->send('GET', '/api/v1/admin/auth/session')->assertOk()->assertJsonPath('permissions', ['setting.view'])->assertJsonPath('is_admin', false);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'rbac.bypass']);
    }

    public function test_role_bundles_differ_on_access_routes(): void
    {
        $target = $this->operator();
        $finance = $this->operator();
        $finance->assignRole('finance');
        $this->login($finance);
        $this->send('GET', $this->path($target).'/access')->assertForbidden();
        $analyst = $this->operator();
        $analyst->assignRole('analyst');
        $this->login($analyst);
        $this->send('GET', $this->path($target).'/access')->assertOk();
    }

    public function test_admin_bypass_is_audited_once_with_request_id_but_never_on_web(): void
    {
        $admin = $this->operator(admin: true);
        $this->login($admin);
        $this->send('GET', '/api/v1/admin/feature-flags')->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rbac.bypass')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'rbac.bypass', 'actor_id' => $admin->id, 'request_id' => 'rbac-test-request', 'reason' => 'is_admin bypass: setting.view']);
        Auth::guard('admin')->setUser($admin);
        Auth::shouldUse('web');
        Auth::guard('web')->setUser($admin);
        $this->assertFalse(Gate::forUser($admin)->allows('setting.update'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rbac.bypass')->count());
        Auth::shouldUse('admin');
        $this->assertFalse(Gate::forUser($this->operator(admin: true))->allows('setting.update'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rbac.bypass')->count());
    }

    public function test_permission_endpoints_audit_before_after_and_replay_without_duplicate_mutations(): void
    {
        $actor = $this->operator();
        $actor->givePermissionTo(['user.view', 'user.update', 'setting.view']);
        $target = $this->operator();
        $this->login($actor);
        $key = (string) Str::uuid();
        $payload = ['permission' => 'setting.view', 'reason' => 'Support access'];
        $this->send('POST', $this->path($target).'/permissions', $payload, key: $key)->assertOk()->assertJsonPath('direct_permissions', ['setting.view']);
        $this->send('POST', $this->path($target).'/permissions', $payload, key: $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rbac.permission.grant')->count());
        $row = DB::table('audit_logs')->where('action', 'rbac.permission.grant')->first();
        $this->assertSame([], json_decode($row->before, true)['direct_permissions']);
        $this->assertSame(['setting.view'], json_decode($row->after, true)['direct_permissions']);
        $this->send('GET', $this->path($target).'/access')->assertOk()->assertJsonPath('permissions', ['setting.view']);
        $this->send('DELETE', $this->path($target).'/permissions/setting.view', ['reason' => 'Support ended'])->assertOk()->assertJsonPath('permissions', []);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rbac.permission.revoke', 'actor_id' => $actor->id, 'target_id' => $target->id]);
    }

    public function test_grant_guards_validation_csrf_idempotency_and_escalation(): void
    {
        $actor = $this->operator();
        $target = $this->operator();
        $customer = $this->operator(operator: false);
        $this->login($actor);
        $body = ['permission' => 'setting.view', 'reason' => 'Support access'];
        $this->send('POST', $this->path($target).'/permissions', $body)->assertForbidden();
        $actor->givePermissionTo('user.update');
        $this->send('POST', $this->path($actor).'/permissions', $body)->assertForbidden();
        $this->send('POST', $this->path($target).'/permissions', $body)->assertForbidden();
        $this->send('POST', $this->path($target).'/roles', ['role' => 'finance', 'reason' => 'Finance access'])->assertForbidden();
        $actor->givePermissionTo('setting.view');
        $this->send('POST', $this->path($customer).'/permissions', $body)->assertUnprocessable();
        $this->send('POST', $this->path($target).'/permissions', ['permission' => 'not.real', 'reason' => 'Support access'])->assertUnprocessable();
        $this->send('POST', $this->path($target).'/roles', ['role' => 'not-real', 'reason' => 'Support access'])->assertUnprocessable();
        $this->send('POST', $this->path($target).'/permissions', $body, csrf: false)->assertStatus(419);
        $this->send('POST', $this->path($target).'/permissions', $body, key: 'invalid')->assertStatus(400);
        foreach ([[], ['permission' => 'setting.view'], ['permission' => 'setting.view', 'reason' => '   '], $body + ['unexpected' => true]] as $invalid) {
            $this->send('POST', $this->path($target).'/permissions', $invalid)->assertUnprocessable();
        }
        $this->send('GET', '/api/v1/admin/users/'.Str::ulid().'/access')->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'rbac.permission.grant']);
    }

    public function test_scope_resolver_scoped_union_global_direct_and_role_revocation(): void
    {
        $admin = $this->operator(admin: true);
        $target = $this->operator();
        $this->login($admin);
        $resolver = app(PermissionScopeResolver::class);
        $this->assertSame([], $resolver->allowed('batch.view', $target));
        foreach ([12, 13] as $id) {
            $this->send('POST', $this->path($target).'/roles', ['role' => 'preorder-ops', 'scope_type' => 'batch', 'scope_id' => $id, 'reason' => 'Batch assignment'])->assertOk();
        }
        $this->assertSame(['batch' => [12, 13]], $resolver->allowed('batch.view', $target));
        $this->assertSame([], $resolver->allowed('setting.view', $target));
        $target->givePermissionTo('batch.view');
        $this->assertNull($resolver->allowed('batch.view', $target));
        $target->revokePermissionTo('batch.view');
        $this->send('POST', $this->path($target).'/roles', ['role' => 'preorder-ops', 'reason' => 'Global coverage'])->assertOk();
        $this->assertNull($resolver->allowed('batch.view', $target));
        $this->send('DELETE', $this->path($target).'/roles/preorder-ops', ['reason' => 'Coverage ended'])->assertOk()->assertJsonPath('roles', []);
        $this->assertSame([], $resolver->allowed('batch.view', $target));
        $this->assertDatabaseMissing('role_scopes', ['user_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rbac.role.assign', 'target_id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rbac.role.revoke', 'target_id' => $target->id]);
        $target->assignRole('preorder-ops');
        $this->assertNull($resolver->allowed('batch.view', $target));
        foreach ([['scope_type' => 'other', 'scope_id' => 1], ['scope_type' => 'batch'], ['scope_id' => 1], ['scope_type' => 'batch', 'scope_id' => 0]] as $invalid) {
            $this->send('POST', $this->path($target).'/roles', $invalid + ['role' => 'preorder-ops', 'reason' => 'Invalid scope'])->assertUnprocessable();
        }
    }

    public function test_non_admin_can_assign_only_a_role_whose_entire_bundle_they_hold(): void
    {
        $actor = $this->operator();
        $target = $this->operator();
        $role = Role::findByName('preorder-ops', 'admin');
        $actor->givePermissionTo($role->permissions);
        $actor->givePermissionTo(['user.view', 'user.update']);
        $this->login($actor);
        $body = ['role' => 'preorder-ops', 'reason' => 'Batch coverage'];
        $this->send('POST', $this->path($target).'/roles', $body)->assertOk()->assertJsonPath('roles.0.scopes.0.scope_type', null);
        $this->send('GET', $this->path($target).'/access')->assertOk()->assertJsonPath('is_admin', false);
        $this->send('DELETE', $this->path($target).'/roles/preorder-ops', ['reason' => 'Coverage ended'])->assertOk();
        $actor->revokePermissionTo('batch.close');
        $this->send('POST', $this->path($target).'/roles', $body)->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'rbac.bypass']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'rbac.role.assign')->count());
    }

    private function operator(bool $admin = false, bool $operator = true): User
    {
        return User::query()->forceCreate(['public_id' => (string) Str::ulid(), 'name' => 'Operator', 'email' => Str::ulid().'@example.test', 'is_operator' => $operator, 'is_admin' => $admin, 'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4])]);
    }

    private function login(User $user): void
    {
        $this->cookies = [];
        $this->app['env'] = 'local';
        config(['app.env' => 'local', 'auth.local_admin_password_only_email' => $user->email]);
        $this->send('GET', '/sanctum/csrf-cookie?scope=admin&purpose=login')->assertNoContent();
        $this->send('POST', '/api/v1/admin/auth/login', ['email' => $user->email, 'password' => 'fixture-password'])->assertOk();
        $this->send('GET', '/sanctum/csrf-cookie?scope=admin')->assertNoContent();
    }

    private function path(User $user): string
    {
        return '/api/v1/admin/users/'.$user->public_id;
    }

    private function send(string $method, string $path, array $payload = [], bool $csrf = true, ?string $key = null): TestResponse
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on', 'HTTP_X_REQUEST_ID' => 'rbac-test-request'];
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        $headers['HTTP_IDEMPOTENCY_KEY'] = $key ?? (string) Str::uuid();
        $response = $this->call($method, $path, [], $this->cookies, [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
