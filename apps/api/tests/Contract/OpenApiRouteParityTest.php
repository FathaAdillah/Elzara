<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Settings\Infrastructure\Http\Middleware\EnsureCheckoutEnabled;
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
}
