<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class StaffRbacContractTest extends TestCase
{
    public function test_access_routes_match_the_permission_csrf_rate_and_idempotency_contract(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        foreach ([
            ['/admin/users/{user}/access', 'get', 'user.view'],
            ['/admin/users/{user}/permissions', 'post', 'user.update'],
            ['/admin/users/{user}/permissions/{permission}', 'delete', 'user.update'],
            ['/admin/users/{user}/roles', 'post', 'user.update'],
            ['/admin/users/{user}/roles/{role}', 'delete', 'user.update'],
        ] as [$path, $method, $permission]) {
            $operation = $spec['paths'][$path][$method];
            $this->assertSame('admin', $operation['x-elzara-auth']);
            $this->assertSame($permission, $operation['x-elzara-permission']);
            $route = Route::getRoutes()->match(Request::create('/api/v1'.strtr($path, ['{user}' => '01J00000000000000000000000', '{permission}' => 'setting.view', '{role}' => 'finance']), strtoupper($method)));
            $middleware = $route->gatherMiddleware();
            $this->assertContains(RequireAdminPermission::class.':'.$permission, $middleware);
            $this->assertSame(['rate:admin'], array_values(array_filter($middleware, fn (string $item): bool => str_starts_with($item, 'rate:'))));
            if ($method !== 'get') {
                $this->assertContains(VerifySessionCsrf::class, $middleware);
                $this->assertCount(1, array_filter($middleware, fn (string $item): bool => str_starts_with($item, 'idempotency:')));
                $this->assertSame('#/components/parameters/AccessIdempotencyKey', $operation['parameters'][1 + (str_contains($path, '{permission}') || str_contains($path, '{role}') ? 1 : 0)]['$ref']);
                $this->assertArrayHasKey('requestBody', $operation);
            }
        }
        $session = $spec['paths']['/admin/auth/session']['get']['responses'][200]['content']['application/json']['schema'];
        $this->assertContains('permissions', $session['required']);
        $this->assertContains('is_admin', $session['required']);
    }
}
