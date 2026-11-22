<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\RequireAdminSession;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use Database\Seeders\Reference\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Contracts\ControllerDispatcher as DispatcherContract;
use Illuminate\Routing\ControllerDispatcher;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class AdminRoleActionMatrixTest extends TestCase
{
    use DatabaseTransactions;

    private const CRUD = ['view', 'create', 'update', 'delete'];

    private const ACTIONS = [
        'audit' => ['view'],
        'batch' => [...self::CRUD, 'close'],
        'billing' => ['view', 'create', 'update', 'void'],
        'cart' => self::CRUD,
        'catalog' => self::CRUD,
        'content' => self::CRUD,
        'inventory' => self::CRUD,
        'logistics' => self::CRUD,
        'marketplace' => ['view', 'create', 'update'],
        'notification' => self::CRUD,
        'order' => ['view', 'create', 'update', 'force_transition'],
        'payment' => ['view', 'create', 'update', 'mark_paid_manual'],
        'procurement' => self::CRUD,
        'promotion' => self::CRUD,
        'report' => ['view'],
        'review' => ['view', 'update', 'delete'],
        'setting' => self::CRUD,
        'shipment' => self::CRUD,
        'sourcing' => self::CRUD,
        'ticket' => self::CRUD,
        'user' => ['view', 'create', 'update'],
    ];

    private const SESSION_ONLY = [
        'POST /admin/media/presign',
        'POST /admin/media/{media_id}/finalize',
        'POST /admin/media/{media_id}/sign',
    ];

    private const CONTRACT_ONLY = [
        'POST /admin/preorder-batches/{id}/close',
        'POST /admin/purchase-orders',
        'POST /admin/shipments',
    ];

    private array $cookies = [];

    private string $clientIp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientIp = '2001:db8:'.implode(':', str_split(bin2hex(random_bytes(12)), 4));
        config(['permission.cache.store' => 'array']);
        app(PermissionRegistrar::class)->initializeCache();
        $this->seed(RolePermissionSeeder::class);
    }

    public static function bundles(): iterable
    {
        $all = self::actions(...array_keys(self::ACTIONS));
        yield 'superadmin' => ['superadmin', $all];
        yield 'catalog-manager' => ['catalog-manager', self::actions('catalog', 'content', 'promotion')];
        yield 'sales-ops' => ['sales-ops', [...self::actions('order'), 'cart.view', 'payment.view', 'user.view']];
        yield 'preorder-ops' => ['preorder-ops', [...self::actions('batch'), 'order.view', 'inventory.view']];
        yield 'sourcing-ops' => ['sourcing-ops', [...self::actions('sourcing'), 'procurement.view', 'marketplace.view']];
        yield 'warehouse-staff' => ['warehouse-staff', [...self::actions('inventory', 'shipment'), 'logistics.view']];
        yield 'procurement-staff' => ['procurement-staff', [...self::actions('procurement', 'logistics'), 'sourcing.view', 'billing.view']];
        yield 'finance' => ['finance', [...self::actions('billing', 'payment', 'report', 'marketplace'), 'order.view']];
        yield 'customer-support' => ['customer-support', [...self::actions('ticket', 'review', 'marketplace'), 'user.view', 'user.update', 'order.view']];
        yield 'analyst' => ['analyst', array_values(array_filter($all, fn (string $ability): bool => str_ends_with($ability, '.view')))];
    }

    private static function actions(string ...$modules): array
    {
        $abilities = [];
        foreach ($modules as $module) {
            foreach (self::ACTIONS[$module] as $verb) {
                $abilities[] = "$module.$verb";
            }
        }

        return $abilities;
    }

    #[DataProvider('bundles')]
    public function test_seeded_role_allows_only_its_documented_actions(string $role, array $allowed): void
    {
        $abilities = self::actions(...array_keys(self::ACTIONS));
        $this->assertEqualsCanonicalizing($abilities, Permission::query()->where('guard_name', 'admin')->pluck('name')->all());
        $this->assertEqualsCanonicalizing(array_keys(iterator_to_array(self::bundles())), Role::query()->where('guard_name', 'admin')->pluck('name')->all());

        $operator = $this->operator($role);
        $this->actingAs($operator, 'admin');
        foreach ($abilities as $ability) {
            $this->assertSame(in_array($ability, $allowed, true), $operator->can($ability), "$role × $ability");
        }
        $this->assertFalse($operator->can('unregistered.matrix_action'), "$role must not bypass the Gate");
        $this->assertDatabaseMissing('audit_logs', ['action' => 'rbac.bypass', 'actor_id' => $operator->id]);
    }

    #[DataProvider('bundles')]
    public function test_shipped_admin_routes_enforce_the_matrix(string $role, array $allowed): void
    {
        $operator = $this->operator($role);
        $this->app['env'] = 'local';
        config(['auth.local_admin_password_only_email' => $operator->email]);
        $this->send('GET', '/sanctum/csrf-cookie?scope=admin&purpose=login')->assertNoContent();
        $this->send('POST', '/api/v1/admin/auth/login', ['email' => $operator->email, 'password' => 'fixture-password'])->assertOk();
        $this->send('GET', '/sanctum/csrf-cookie?scope=admin')->assertNoContent();

        // Keep routing, sessions, CSRF and permissions real; avoid unrelated controller validation and writes.
        $this->app->instance(DispatcherContract::class, new class($this->app) extends ControllerDispatcher
        {
            public function dispatch(Route $route, $controller, $method): Response
            {
                return response()->noContent()->header('X-Matrix-Controller', $route->uri());
            }
        });

        foreach ($this->shippedOperations() as $operation => $permissions) {
            [$method, $path] = explode(' ', $operation, 2);
            $url = strtr($path, ['{user}' => $operator->public_id, '{id}' => '1', '{media_id}' => '1', '{role}' => 'analyst', '{permission}' => 'catalog.view']);
            $response = $this->send($method, '/api/v1'.$url);
            $granted = array_diff($permissions, $allowed) === [];
            $expected = $granted ? 204 : 403;
            $this->assertSame($expected, $response->status(), "$role × $operation: ".$response->getContent());
            if ($granted) {
                $response->assertHeader('X-Matrix-Controller', 'api/v1'.$path);
            } else {
                $response->assertHeaderMissing('X-Matrix-Controller');
            }
        }
    }

    private function shippedOperations(): array
    {
        $operations = [];
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        foreach ($spec['paths'] as $path => $methods) {
            if (! str_starts_with($path, '/admin/') || str_starts_with($path, '/admin/auth/')) {
                continue;
            }
            foreach (array_intersect_key($methods, array_flip(['get', 'post', 'put', 'patch', 'delete'])) as $method => $specOperation) {
                $key = strtoupper($method).' '.$path;
                if (in_array($key, self::SESSION_ONLY, true)) {
                    $this->assertArrayNotHasKey('x-elzara-permission', $specOperation, $key);
                    $operations[$key] = [];
                } else {
                    $this->assertArrayHasKey('x-elzara-permission', $specOperation, $key);
                    $operations[$key] = [$specOperation['x-elzara-permission'], ...($specOperation['x-elzara-additional-permissions'] ?? [])];
                    $this->assertSame([], array_diff($operations[$key], self::actions(...array_keys(self::ACTIONS))), $key);
                }
            }
        }

        $registered = [];
        foreach (Router::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/admin/') || str_starts_with($route->uri(), 'api/v1/admin/auth/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $key = $method.' '.substr($route->uri(), strlen('api/v1'));
                $this->assertArrayHasKey($key, $operations, $key);
                $middleware = $route->gatherMiddleware();
                $this->assertEqualsCanonicalizing(
                    array_map(fn (string $permission): string => RequireAdminPermission::class.':'.$permission, $operations[$key]),
                    array_values(array_filter($middleware, fn (string $item): bool => str_starts_with($item, RequireAdminPermission::class.':'))),
                    $key,
                );
                if (in_array($key, self::SESSION_ONLY, true)) {
                    $this->assertContains(RequireAdminSession::class, $middleware, $key);
                }
                $registered[] = $key;
            }
        }
        $this->assertEqualsCanonicalizing(self::CONTRACT_ONLY, array_values(array_diff(array_keys($operations), $registered)));

        return array_intersect_key($operations, array_flip($registered));
    }

    private function operator(string $role): User
    {
        $operator = User::query()->forceCreate([
            'public_id' => (string) Str::ulid(),
            'name' => 'Matrix Operator',
            'email' => Str::ulid().'@example.test',
            'is_operator' => true,
            'is_admin' => false,
            'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        $operator->assignRole($role);

        return $operator;
    }

    private function send(string $method, string $path, array $payload = []): TestResponse
    {
        $server = [
            'REMOTE_ADDR' => $this->clientIp,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://localhost',
            'HTTPS' => 'on',
            'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid(),
        ];
        if (isset($this->cookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $this->cookies, [], $server, json_encode($payload ?: (object) [], JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
