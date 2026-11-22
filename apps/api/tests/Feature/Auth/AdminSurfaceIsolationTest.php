<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AdminSurfaceIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'surfaces.admin_origin' => 'https://admin.localhost:8443',
            'surfaces.storefront_origin' => 'https://localhost:8443',
            'sanctum.stateful' => ['localhost:8443', 'admin.localhost:8443'],
        ]);
    }

    public static function adminPaths(): array
    {
        return [
            ['GET', '/sanctum/csrf-cookie?scope=admin'],
            ['GET', '/api/v1/admin/auth/session'],
            ['POST', '/api/v1/admin/auth/login'],
            ['POST', '/api/v1/admin/auth/2fa/verify'],
            ['POST', '/api/v1/admin/auth/logout'],
            ['PATCH', '/api/v1/admin/catalog/products/example'],
        ];
    }

    #[DataProvider('adminPaths')]
    public function test_admin_surface_rejects_storefront_and_spoofed_forwarded_host_before_cookies(string $method, string $path): void
    {
        foreach (['localhost:8443', 'nginx:8080', 'admin.localhost:9443'] as $host) {
            $this->request($method, $path, $host, ['Origin' => 'https://admin.localhost:8443', 'X-Forwarded-Host' => 'admin.localhost:8443'])
                ->assertForbidden()->assertJsonPath('code', 'ADMIN_HOST_REQUIRED')->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_storefront_routes_cannot_issue_cookies_on_admin_host(): void
    {
        foreach (['/sanctum/csrf-cookie', '/api/v1/auth/login', '/api/v1/me/sessions', '/api/v1/me/addresses'] as $path) {
            $this->request('GET', $path)->assertForbidden()->assertJsonPath('code', 'STOREFRONT_HOST_REQUIRED')->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_trusted_proxy_cannot_replace_host_or_port_using_client_forwarded_headers(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $this->request('GET', '/sanctum/csrf-cookie?scope=admin', 'localhost:8443', [
            'Origin' => 'https://admin.localhost:8443',
            'X-Forwarded-Host' => 'admin.localhost:8443',
            'X-Forwarded-Proto' => 'https',
        ])->assertForbidden()->assertHeaderMissing('Set-Cookie');
        $this->request('GET', '/sanctum/csrf-cookie?scope=admin', 'admin.localhost:9443', [
            'X-Forwarded-Port' => '8443',
        ])->assertForbidden()->assertHeaderMissing('Set-Cookie');
        $this->request('GET', '/sanctum/csrf-cookie?scope=admin', headers: [
            'X-Forwarded-Host' => 'localhost:8443',
            'X-Forwarded-Proto' => 'https',
        ])->assertNoContent();
    }

    public function test_web_csrf_token_and_session_cannot_authorize_admin_mutations(): void
    {
        $web = $this->request('GET', '/sanctum/csrf-cookie', 'localhost:8443')->assertNoContent();
        $cookies = [];
        foreach ($web->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        foreach (['login', '2fa/verify', 'logout'] as $action) {
            $this->call('POST', 'https://admin.localhost:8443/api/v1/admin/auth/'.$action, cookies: $cookies, server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => 'https://admin.localhost:8443',
                'HTTP_X_XSRF_TOKEN' => $cookies['XSRF-TOKEN'],
            ])->assertStatus(419);
        }
    }

    public static function rejectedHeaders(): array
    {
        return [
            [[]],
            [['Origin' => 'null']],
            [['Origin' => 'https://localhost:8443']],
            [['Origin' => 'https://sibling.localhost:8443']],
            [['Origin' => 'https://foreign.test']],
            [['Origin' => 'http://admin.localhost:8443']],
            [['Origin' => 'https://admin.localhost']],
            [['Origin' => 'https://admin.localhost:8443/']],
            [['Referer' => 'https://localhost:8443/page']],
            [['Origin' => 'https://admin.localhost:8443', 'Referer' => 'https://localhost:8443/page']],
            [['Origin' => 'https://localhost:8443', 'Referer' => 'https://admin.localhost:8443/page']],
            [['Referer' => 'https://admin.localhost:8443@foreign.test/']],
        ];
    }

    #[DataProvider('rejectedHeaders')]
    public function test_admin_mutations_reject_missing_foreign_or_conflicting_origins(array $headers): void
    {
        foreach (['login', '2fa/verify', 'logout'] as $action) {
            $this->request('POST', '/api/v1/admin/auth/'.$action, headers: $headers)
                ->assertForbidden()->assertJsonPath('code', 'ADMIN_ORIGIN_REQUIRED')->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_valid_origin_or_referer_still_requires_csrf_token(): void
    {
        foreach ([['Origin' => 'https://admin.localhost:8443'], ['Referer' => 'https://admin.localhost:8443/login'], ['Origin' => 'https://admin.localhost:8443', 'Referer' => 'https://admin.localhost:8443/login']] as $headers) {
            foreach (['login', '2fa/verify', 'logout'] as $action) {
                $this->request('POST', '/api/v1/admin/auth/'.$action, headers: $headers)->assertStatus(419);
            }
        }
    }

    public function test_admin_safe_requests_validate_any_present_origin_or_referer(): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $this->request($method, '/sanctum/csrf-cookie?scope=admin')->assertNoContent();
            foreach ([['Origin' => 'null'], ['Referer' => 'https://localhost:8443/'], ['Origin' => 'https://admin.localhost:8443', 'Referer' => 'https://foreign.test/']] as $headers) {
                $this->request($method, '/sanctum/csrf-cookie?scope=admin', headers: $headers)->assertForbidden()->assertHeaderMissing('Set-Cookie');
            }
        }
    }

    public function test_bootstrap_cookies_are_host_only_on_their_own_surface(): void
    {
        foreach ([['admin.localhost:8443', '?scope=admin&purpose=login', 'elzara_admin_challenge'], ['localhost:8443', '', 'elzara_web_session']] as [$host, $query, $session]) {
            $response = $this->request('GET', '/sanctum/csrf-cookie'.$query, $host)->assertNoContent();
            $names = [];
            foreach ($response->headers->getCookies() as $cookie) {
                $names[] = $cookie->getName();
                $this->assertNull($cookie->getDomain());
                $this->assertStringNotContainsString('domain=', strtolower((string) $cookie));
            }
            $this->assertContains($session, $names);
            $this->assertContains('XSRF-TOKEN', $names);
        }
    }

    public function test_ssr_with_admin_host_and_origin_is_not_rejected_by_host_gate(): void
    {
        $this->request('GET', '/api/v1/admin/auth/session', headers: ['Origin' => 'https://admin.localhost:8443'])->assertUnauthorized();
        config(['surfaces.admin_origin' => 'https://admin.localhost', 'sanctum.stateful' => ['admin.localhost']]);
        $this->call('GET', 'http://admin.localhost/api/v1/admin/auth/session', server: ['HTTP_HOST' => 'admin.localhost', 'HTTP_ORIGIN' => 'https://admin.localhost', 'HTTP_ACCEPT' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_unset_origin_is_legacy_only_outside_production_and_invalid_origin_always_fails_closed(): void
    {
        config(['surfaces.admin_origin' => null]);
        $this->request('GET', '/sanctum/csrf-cookie?scope=admin', 'localhost:8443')->assertNoContent();
        app()->instance('env', 'production');
        $this->request('GET', '/sanctum/csrf-cookie?scope=admin')->assertStatus(503)->assertHeaderMissing('Set-Cookie');
        foreach (['null', 'https://admin.localhost/path', 'https://user:pass@admin.localhost', 'ftp://admin.localhost'] as $origin) {
            config(['surfaces.admin_origin' => $origin]);
            $this->request('GET', '/sanctum/csrf-cookie?scope=admin')->assertStatus(503)->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_cors_preflight_and_actual_responses_never_allow_the_other_surface(): void
    {
        foreach ([['admin.localhost:8443', '/sanctum/csrf-cookie?scope=admin', 'https://admin.localhost:8443'], ['admin.localhost:8443', '/api/v1/admin/auth/session', 'https://admin.localhost:8443'], ['localhost:8443', '/api/v1/auth/login', 'https://localhost:8443'], ['localhost:8443', '/sanctum/csrf-cookie', 'https://localhost:8443']] as [$host, $path, $allowed]) {
            foreach (['OPTIONS', 'GET'] as $method) {
                foreach (['https://admin.localhost:8443', 'https://localhost:8443', 'https://foreign.test', 'null'] as $origin) {
                    $response = $this->request($method, $path, $host, ['Origin' => $origin, 'Access-Control-Request-Method' => 'POST']);
                    if ($origin === $allowed) {
                        $response->assertHeader('Access-Control-Allow-Origin', $origin);
                    } else {
                        $response->assertHeaderMissing('Access-Control-Allow-Origin');
                    }
                    $response->assertHeaderMissing('Access-Control-Allow-Credentials');
                }
            }
        }
    }

    public function test_production_rejects_parent_session_domain_at_configuration_load(): void
    {
        foreach (['production', false] as $environment) {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'about', '--only=environment'],
                base_path(),
                ['APP_ENV' => $environment, 'SESSION_DOMAIN' => '.localhost'],
            );
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('SESSION_DOMAIN must be empty in production.', $process->getOutput().$process->getErrorOutput());
        }
    }

    private function request(string $method, string $path, string $host = 'admin.localhost:8443', array $headers = []): TestResponse
    {
        $server = ['HTTP_HOST' => $host, 'HTTPS' => 'on', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->call($method, 'https://'.$host.$path, server: $server);
    }
}
