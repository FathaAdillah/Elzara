<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use App\Modules\Settings\Infrastructure\Http\Middleware\EnsureCheckoutEnabled;
use App\Modules\Settings\Infrastructure\Http\RequireUuidIdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class OpenApiRouteParityTest extends TestCase
{
    public function test_public_catalog_routes_match_contract_operations_and_rate_policy(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        foreach (['/products' => 'listProducts', '/products/{slug}' => 'getProduct', '/categories' => 'listCategories'] as $path => $operation) {
            $this->assertSame($operation, $spec['paths'][$path]['get']['operationId']);
            $route = Route::getRoutes()->match(Request::create('/api/v1'.str_replace('{slug}', 'gundam', $path), 'GET'));
            $this->assertContains('rate:public', $route->gatherMiddleware());
            $this->assertSame([], $spec['paths'][$path]['get']['security']);
            foreach ([422 => 'Problem', 429 => 'RateLimited'] as $status => $response) {
                $this->assertSame('#/components/responses/'.$response, $spec['paths'][$path]['get']['responses'][$status]['$ref']);
            }
        }
        $this->assertSame('#/components/responses/Problem', $spec['paths']['/products/{slug}']['get']['responses'][404]['$ref']);
    }

    public function test_social_callback_declares_conflicts_as_problem_details(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        $operation = $spec['paths']['/auth/social/{provider}/callback']['get'];
        $route = Route::getRoutes()->match(Request::create('/api/v1/auth/social/google/callback', 'GET'));

        $this->assertSame('handleSocialCallback', $operation['operationId']);
        $this->assertSame('App\\Modules\\Identity\\Infrastructure\\Http\\SocialAuthController@callback', $route->getActionName());
        $this->assertSame([], $operation['security']);
        $this->assertSame('#/components/responses/Problem', $operation['responses'][409]['$ref']);
        $this->assertArrayHasKey('application/problem+json', $spec['components']['responses']['Problem']['content']);
    }

    public function test_flag_routes_methods_permissions_and_checkout_disabled_contract(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        $routes = Route::getRoutes();
        foreach (['/feature-flags' => ['get' => 'publicFeatureFlags'], '/admin/feature-flags' => ['get' => 'adminFeatureFlagsList', 'patch' => 'adminFeatureFlagsPatch']] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $this->assertSame($operation, $spec['paths'][$path][$method]['operationId']);
                $route = $routes->match(Request::create('/api/v1'.$path, strtoupper($method)));
                $this->assertContains(strtoupper($method), $route->methods());
                $this->assertContains($path === '/admin/feature-flags' ? 'rate:admin' : 'rate:public', $route->gatherMiddleware());
                $this->assertSame('#/components/responses/RateLimited', $spec['paths'][$path][$method]['responses'][429]['$ref']);
                foreach (['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'X-RateLimit-Policy', 'X-Displayed-Timezone'] as $header) {
                    $this->assertArrayHasKey($header, $spec['paths'][$path][$method]['responses'][200]['headers']);
                }
                if ($path === '/admin/feature-flags') {
                    $permission = $spec['paths'][$path][$method]['x-elzara-permission'];
                    $this->assertContains(RequireAdminPermission::class.':'.$permission, $route->gatherMiddleware());
                }
            }
        }
        $order = $routes->match(Request::create('/api/v1/orders', 'POST'));
        $this->assertContains(EnsureCheckoutEnabled::class, $order->gatherMiddleware());
        $this->assertContains('rate:web', $order->gatherMiddleware());
        $this->assertArrayHasKey('503', $spec['paths']['/orders']['post']['responses']);
        $this->assertSame('CHECKOUT_DISABLED', $spec['paths']['/orders']['post']['responses'][503]['content']['application/problem+json']['schema']['allOf'][1]['properties']['code']['const']);
    }

    public function test_admin_catalog_routes_match_openapi_both_ways(): void
    {
        $spec = Yaml::parseFile(base_path('../../packages/api-contracts/openapi.yaml'));
        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            if (! str_starts_with($path, '/admin/catalog/')) {
                continue;
            }
            foreach (array_intersect_key($operations, array_flip(['get', 'post', 'put', 'patch', 'delete'])) as $method => $operation) {
                $documented[] = strtoupper($method).' '.$path;
                $route = Route::getRoutes()->match(Request::create('/api/v1'.str_replace('{id}', '1', $path), strtoupper($method)));
                $middleware = $route->gatherMiddleware();
                $this->assertContains('rate:admin', $middleware, $path);
                $this->assertContains(RequireAdminPermission::class.':'.$operation['x-elzara-permission'], $middleware, $path);
                foreach ($operation['x-elzara-additional-permissions'] ?? [] as $permission) {
                    $this->assertContains(RequireAdminPermission::class.':'.$permission, $middleware, $path);
                }
                if ($method !== 'get') {
                    $this->assertContains(VerifySessionCsrf::class, $middleware, $path);
                    $refs = array_column($operation['parameters'], '$ref');
                    $this->assertContains('#/components/parameters/XsrfToken', $refs, $path);
                    $this->assertSame(in_array(RequireUuidIdempotencyKey::class, $middleware, true), in_array('#/components/parameters/IdempotencyKey', $refs, true), $path);
                }
            }
        }

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/admin/catalog/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $registered[] = $method.' '.substr($route->uri(), strlen('api/v1'));
                }
            }
        }
        sort($documented);
        sort($registered);
        $this->assertSame($registered, $documented);
    }
}
