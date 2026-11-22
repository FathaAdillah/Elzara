<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Http\RatePolicy;
use App\Platform\Http\StrictValidation;
use App\Platform\Http\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiConventionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_every_api_route_declares_one_known_surface_and_its_policy(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = substr($route->uri(), 7);
            $middleware = $route->gatherMiddleware();
            $surface = match (true) {
                str_starts_with($path, 'admin/') => 'admin',
                str_starts_with($path, 'internal/') => 'internal',
                str_starts_with($path, 'webhooks/') => 'webhooks',
                in_array('rate:web', $middleware, true) => 'web',
                default => 'public',
            };
            $this->assertContains($surface === 'internal' ? 'internal' : 'rate:'.$surface, $middleware, $route->uri());
            $declared = array_intersect(['internal', 'rate:public', 'rate:web', 'rate:admin', 'rate:webhooks'], $middleware);
            $this->assertCount(1, $declared, $route->uri());
        }
    }

    public function test_internal_access_uses_configured_cidrs_and_hides_routes(): void
    {
        Route::get('api/v1/internal/probe', fn () => ['ok' => true])->middleware('internal');
        config(['api.internal_cidrs' => ['10.20.0.0/16', '::1/128']]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->getJson('/api/v1/internal/probe')
            ->assertNotFound()->assertJsonPath('type', 'urn:elzara:problem:not-found:resource');
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.4.5'])->getJson('/api/v1/internal/probe')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '::1'])->getJson('/api/v1/internal/probe')->assertOk();
        config(['api.internal_cidrs' => []]);
        $this->getJson('/api/v1/internal/probe')->assertNotFound();
    }

    public function test_rate_headers_cover_success_errors_and_limit_exhaustion(): void
    {
        config(['api.rate_limits.public' => 2]);
        Route::get('api/v1/probe', function (Request $request) {
            abort_if($request->boolean('fail'), 404);

            return ['ok' => true];
        })->middleware('rate:public');
        $this->getJson('/api/v1/probe')->assertOk()->assertHeader('X-RateLimit-Limit', '2')
            ->assertHeader('X-RateLimit-Remaining', '1')->assertHeader('X-RateLimit-Policy', 'public');
        $error = $this->getJson('/api/v1/probe?fail=1')->assertNotFound()->assertHeader('X-RateLimit-Remaining', '0');
        $this->assertGreaterThanOrEqual(time(), (int) $error->headers->get('X-RateLimit-Reset'));
        $this->getJson('/api/v1/probe')->assertStatus(429)->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Policy', 'public')->assertJsonPath('type', 'urn:elzara:problem:rate-limit:exceeded');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->getJson('/api/v1/probe')->assertOk();
        $this->getJson('/api/v1/missing')->assertNotFound()->assertHeader('X-RateLimit-Policy');
    }

    public function test_storefront_server_calls_are_limited_per_forwarded_shopper(): void
    {
        $key = str_repeat('k', 40);
        config(['api.rate_limits.public' => 2, 'api.storefront_proxy_key' => $key]);
        Route::get('api/v1/shopper-probe', fn () => ['ok' => true])->middleware('rate:public');
        $storefront = fn (array $headers) => $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])
            ->withHeaders($headers)->getJson('/api/v1/shopper-probe');
        $asShopper = fn (string $ip) => $storefront([RatePolicy::STOREFRONT_KEY_HEADER => $key, RatePolicy::SHOPPER_IP_HEADER => $ip]);

        $asShopper('203.0.113.10')->assertOk();
        $asShopper('203.0.113.10')->assertOk();
        $asShopper('203.0.113.10')->assertStatus(429);
        $asShopper('198.51.100.20')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        $asShopper('2001:db8::7')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');

        // Without the key the shopper header is ignored: the storefront IP keeps one bucket.
        $storefront([RatePolicy::SHOPPER_IP_HEADER => '192.0.2.1'])->assertOk();
        $storefront([RatePolicy::STOREFRONT_KEY_HEADER => str_repeat('x', 40), RatePolicy::SHOPPER_IP_HEADER => '192.0.2.2'])->assertOk();
        $storefront([RatePolicy::SHOPPER_IP_HEADER => '192.0.2.3'])->assertStatus(429);
        $storefront([RatePolicy::STOREFRONT_KEY_HEADER => $key, RatePolicy::SHOPPER_IP_HEADER => 'not-an-ip'])->assertStatus(429);
        $storefront([RatePolicy::STOREFRONT_KEY_HEADER => $key])->assertStatus(429);
    }

    public function test_forwarded_shopper_ip_needs_a_configured_strong_key(): void
    {
        $request = fn (string $presented) => tap(Request::create('/api/v1/products', server: ['REMOTE_ADDR' => '172.18.0.5']), function (Request $request) use ($presented) {
            $request->headers->set(RatePolicy::STOREFRONT_KEY_HEADER, $presented);
            $request->headers->set(RatePolicy::SHOPPER_IP_HEADER, '203.0.113.10');
        });
        foreach (['', 'short-key'] as $configured) {
            config(['api.storefront_proxy_key' => $configured]);
            $this->assertSame('public|ip:172.18.0.5', RatePolicy::key($request($configured), 'public'));
        }
        config(['api.storefront_proxy_key' => str_repeat('k', 32)]);
        $this->assertSame('public|ip:203.0.113.10', RatePolicy::key($request(str_repeat('k', 32)), 'public'));
        $this->assertSame('public|ip:172.18.0.5', RatePolicy::key($request(str_repeat('k', 31)), 'public'));
    }

    public function test_operator_and_web_buckets_are_per_account_not_ip(): void
    {
        config(['api.rate_limits.admin' => 1, 'api.rate_limits.web' => 1]);
        foreach (['admin', 'web'] as $guard) {
            Route::get('api/v1/buckets/'.$guard, fn () => ['ok' => true])->middleware('rate:'.$guard);
            foreach ([71, 72] as $id) {
                $user = new User;
                $user->forceFill(['id' => $id, 'timezone' => 'Asia/Makassar']);
                $this->actingAs($user, $guard);
                $this->getJson('/api/v1/buckets/'.$guard)->assertOk()->assertHeader('X-Displayed-Timezone', 'Asia/Makassar');
                $this->getJson('/api/v1/buckets/'.$guard)->assertStatus(429);
            }
            Auth::forgetGuards();
        }
    }

    public function test_named_policies_are_registered_and_webhook_scope_includes_provider(): void
    {
        $request = Request::create('/api/v1/webhooks/alpha');
        $other = Request::create('/api/v1/webhooks/beta');
        foreach (RatePolicy::NAMES as $policy) {
            $this->assertNotNull(app(RateLimiter::class)->limiter($policy));
        }
        $this->assertNotSame(RatePolicy::limit($request, 'webhooks')->key, RatePolicy::limit($other, 'webhooks')->key);
        $this->assertNotSame(
            RatePolicy::key(Request::create('/api/v1/webhooks/payments/midtrans'), 'webhooks'),
            RatePolicy::key(Request::create('/api/v1/webhooks/payments/xendit'), 'webhooks'),
        );
        $this->assertSame(RatePolicy::limit($request, 'public')->key, RatePolicy::limit($other, 'public')->key);
    }

    public function test_strict_validation_rejects_body_and_query_unknown_fields(): void
    {
        Route::post('api/v1/validate', fn (Request $request) => StrictValidation::validate($request, ['name' => 'required|string']));
        $this->postJson('/api/v1/validate?extra=1', ['name' => 'valid'])->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonPath('errors.0.pointer', '/extra')->assertJsonPath('errors.0.code', 'unknown_field');
        $this->postJson('/api/v1/validate', ['name' => 'valid', 'a/b~' => true])->assertUnprocessable()
            ->assertJsonPath('errors.0.pointer', '/a~1b~0')->assertJsonPath('errors.0.code', 'unknown_field');
        $this->postJson('/api/v1/validate', [])->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/name');
        $this->postJson('/api/v1/validate', ['name' => 'valid'])->assertOk()->assertExactJson(['name' => 'valid']);
    }

    public function test_wire_time_is_utc_with_millisecond_precision_without_mutating_input(): void
    {
        $time = CarbonImmutable::parse('2026-11-17T11:12:33.123456+07:00');
        $this->assertSame('2026-11-17T04:12:33.123Z', WireTime::format($time));
        $this->assertSame('+07:00', $time->format('P'));
    }
}
