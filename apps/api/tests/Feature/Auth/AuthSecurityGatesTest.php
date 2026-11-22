<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Laravel\Socialite\Two\XProvider;
use Mockery;
use OTPHP\TOTP;
use Tests\TestCase;

final class AuthSecurityGatesTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const PASSWORD = 'fixture-password';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['auth.local_admin_password_only_email' => null]);
        Route::pushMiddlewareToGroup('api', GuardSnapshot::class);
    }

    public function test_sixth_failed_admin_login_is_rate_limited(): void
    {
        $user = $this->account();
        $cookies = $this->csrf(admin: true);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->send('POST', '/api/v1/admin/auth/login', $cookies, ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->send('POST', '/api/v1/admin/auth/login', $cookies, ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->id]);
    }

    public function test_totp_authenticates_the_guard_and_rotates_session_and_admin_cookie_cannot_authenticate_web(): void
    {
        config(['app.env' => 'production', 'session.secure' => true]);
        $user = $this->account();
        $this->factor($user);
        $cookies = $this->csrf(admin: true);
        $pending = $this->send('POST', '/api/v1/admin/auth/login', $cookies, ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('two_factor_required', true)->assertHeader('X-Test-Admin-Authenticated', 'false')
            ->assertCookieMissing('elzara_admin_session');
        $before = $pending->headers->get('X-Test-Session-Id');
        $this->assertNotEmpty($before);
        $cookies = $this->csrf($this->jar($pending) + $cookies, admin: true);
        $verified = $this->send('POST', '/api/v1/admin/auth/2fa/verify', $cookies, [
            'challenge_id' => $pending->json('challenge_id'),
            'code' => TOTP::createFromSecret(self::SECRET)->at(now()->getTimestamp()),
        ])->assertOk()->assertHeader('X-Test-Admin-Authenticated', 'true');
        $this->assertNotSame($before, $verified->headers->get('X-Test-Session-Id'));
        $this->assertSessionCookie($verified, 'elzara_admin_session');
        $admin = $this->jar($verified);
        $this->send('GET', '/api/v1/admin/auth/session', $admin)->assertOk()->assertHeader('X-Test-Admin-Authenticated', 'true');
        $this->send('GET', '/api/v1/me/sessions', ['elzara_admin_session' => $admin['elzara_admin_session']])->assertUnauthorized();
    }

    public function test_unconfirmed_factor_cannot_start_an_admin_challenge(): void
    {
        $user = $this->account();
        $this->factor($user, confirmed: false);
        $this->send('POST', '/api/v1/admin/auth/login', $this->csrf(admin: true), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertForbidden()->assertCookieMissing('elzara_admin_session')->assertJsonMissingPath('challenge_id');
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->id]);
    }

    public function test_web_session_cookie_has_production_security_attributes(): void
    {
        config(['app.env' => 'production', 'session.secure' => true]);
        $user = $this->account();
        $response = $this->send('POST', '/api/v1/auth/login', $this->csrf(), ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertSessionCookie($response, 'elzara_web_session');
    }

    public function test_unverified_provider_email_returns_http_conflict_without_linking(): void
    {
        $user = $this->account();
        $user->forceFill(['email_verified_at' => now()])->save();
        $social = (new SocialiteUser)->setRaw(['email_verified' => false])->map([
            'id' => 'google-'.Str::ulid(), 'email' => $user->email, 'name' => 'Provider user',
        ]);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('user')->once()->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
        $count = DB::table('user_identities')->count();
        $this->send('GET', '/api/v1/auth/social/google/callback?code=fixture&state=fixture', $this->csrf())->assertStatus(409);
        $this->assertDatabaseCount('user_identities', $count);
        $this->assertDatabaseMissing('user_sessions', ['user_id' => $user->id]);
    }

    public function test_x_uses_the_oauth2_driver_and_callback(): void
    {
        config(['services.x.client_id' => 'fixture', 'services.x.client_secret' => 'fixture']);
        $driver = Socialite::driver('x');
        $this->assertInstanceOf(XProvider::class, $driver);
        $this->assertInstanceOf(AbstractProvider::class, $driver);
        $social = (new SocialiteUser)->map(['id' => 'x-'.Str::ulid(), 'name' => 'X user', 'email' => null]);
        $mock = Mockery::mock(XProvider::class);
        $mock->shouldReceive('user')->once()->andReturn($social);
        Socialite::shouldReceive('driver')->with('x')->once()->andReturn($mock);
        $this->send('GET', '/api/v1/auth/social/x/callback?code=fixture&state=fixture', $this->csrf())->assertRedirect(url('/account'));
        $this->assertDatabaseHas('user_identities', ['provider' => 'x', 'provider_user_id' => $social->getId()]);
    }

    private function account(): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::ulid(), 'name' => 'Auth fixture', 'email' => Str::lower((string) Str::ulid()).'@example.test',
            'is_operator' => true, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
    }

    private function factor(User $user, bool $confirmed = true): void
    {
        DB::insert("INSERT INTO staff_2fa_secrets (user_id, secret_encrypted, confirmed_at) VALUES (?, convert_to(?, 'UTF8'), ?)", [
            $user->id, Crypt::encryptString(self::SECRET), $confirmed ? now() : null,
        ]);
    }

    private function assertSessionCookie(TestResponse $response, string $name): void
    {
        $cookies = array_values(array_filter($response->headers->getCookies(), fn ($cookie) => $cookie->getName() === $name && ! $cookie->isCleared()));
        $this->assertCount(1, $cookies);
        $cookie = $cookies[0];
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
    }

    private function csrf(array $cookies = [], bool $admin = false): array
    {
        $response = $this->send('GET', '/sanctum/csrf-cookie'.($admin ? '?scope=admin&purpose=login' : ''), $cookies)->assertNoContent();

        return $this->jar($response) + $cookies;
    }

    private function jar(TestResponse $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $cookies;
    }

    private function send(string $method, string $path, array $cookies, array $payload = []): TestResponse
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on'];
        if (isset($cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $cookies['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $cookies, [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
        $this->app['auth']->forgetGuards();

        return $response;
    }
}
