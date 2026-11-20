<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OTPHP\TOTP;
use Tests\TestCase;

final class AdminLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const RECOVERY = 'A1B2C3D4E5F6';

    private const PASSWORD = 'fixture-password';

    private const CHALLENGE_COOKIE = 'elzara_admin_challenge';

    private const SESSION_COOKIE = 'elzara_admin_session';

    private const LOGIN = '/api/v1/admin/auth/login';

    private const VERIFY = '/api/v1/admin/auth/2fa/verify';

    private const SESSION = '/api/v1/admin/auth/session';

    private const LOGOUT = '/api/v1/admin/auth/logout';

    public function test_login_enforces_csrf_password_and_operator_status(): void
    {
        $operator = $this->operator();
        $customer = $this->operator(false);
        $suspended = $this->operator(true, 'suspended');
        $cookies = $this->csrf([], login: true);

        $this->request('POST', self::LOGIN, $cookies, $this->credentials($operator), false)->assertStatus(419);
        $invalidCsrf = array_replace($cookies, ['XSRF-TOKEN' => 'invalid-token']);
        $this->request('POST', self::LOGIN, $invalidCsrf, $this->credentials($operator))->assertStatus(419);
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($operator, 'wrong-password'))->assertUnauthorized();
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($customer))->assertForbidden();
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($suspended))->assertForbidden();
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_pending_challenge_becomes_verified_admin_session_and_logout_revokes_it(): void
    {
        $operator = $this->operator();
        [$challenge, $cookies] = $this->challenge($operator);
        $this->request('GET', self::SESSION, $cookies)->assertUnauthorized();
        $code = TOTP::createFromSecret(self::SECRET)->at(now()->getTimestamp());

        $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => 'wrong', 'code' => $code])->assertUnauthorized();
        $wrongCode = $code === '000000' ? '999999' : '000000';
        $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => $challenge, 'code' => $wrongCode])->assertUnauthorized();
        $response = $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('authenticated', true)->assertJsonPath('user.email', $operator);
        $admin = $this->cookieJar($response);
        $this->assertArrayHasKey(self::SESSION_COOKIE, $admin);
        $this->request('GET', self::SESSION, $admin)->assertOk()->assertJsonPath('user.email', $operator);
        $this->request('GET', self::SESSION, ['elzara_web_session' => $admin[self::SESSION_COOKIE]])->assertUnauthorized();
        $replay = ['challenge_id' => $challenge, 'code' => $code];
        $this->request('POST', self::VERIFY, $cookies, $replay)->assertStatus(419);
        $this->request('POST', self::VERIFY, $this->csrf($cookies, login: true), $replay)->assertUnauthorized();

        $admin = $this->csrf($admin);
        $this->request('POST', self::LOGOUT, $admin, [], false)->assertStatus(419);
        $this->request('POST', self::LOGOUT, $admin)->assertNoContent();
        $this->request('GET', self::SESSION, $admin)->assertUnauthorized();
        $this->assertTrue(DB::table('user_sessions')->whereNotNull('revoked_at')->exists());
    }

    public function test_totp_and_recovery_code_cannot_be_reused_for_a_fresh_challenge(): void
    {
        $operator = $this->operator();
        $totp = TOTP::createFromSecret(self::SECRET)->at(now()->getTimestamp());

        foreach ([$totp, self::RECOVERY] as $code) {
            [$challenge, $cookies] = $this->challenge($operator);
            $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => $challenge, 'code' => $code])->assertOk();

            [$freshChallenge, $freshCookies] = $this->challenge($operator);
            $this->request('POST', self::VERIFY, $freshCookies, [
                'challenge_id' => $freshChallenge,
                'code' => $code,
            ])->assertUnauthorized();
        }

        $this->assertTrue(DB::table('staff_2fa_secrets')->whereRaw('cardinality(recovery_hashes) = 0')->exists());
    }

    public function test_password_only_login_is_scoped_to_one_local_operator(): void
    {
        app()->instance('env', 'local');
        $allowed = $this->operator(withFactor: false);
        $other = $this->operator(withFactor: false);
        config(['auth.local_admin_password_only_email' => $allowed]);
        $cookies = $this->csrf([], login: true);

        $this->request('POST', self::LOGIN, $cookies, $this->credentials($allowed), false)->assertStatus(419);
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($allowed, 'wrong-password'))->assertUnauthorized();
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($other))->assertForbidden();
        $response = $this->request('POST', self::LOGIN, $cookies, $this->credentials($allowed))
            ->assertOk()->assertJsonPath('two_factor_required', false)->assertJsonPath('authenticated', true);
        $admin = $this->cookieJar($response);
        $this->assertArrayHasKey(self::SESSION_COOKIE, $admin);
        $this->request('GET', self::SESSION, $admin)->assertOk()->assertJsonPath('user.email', $allowed);

        config(['auth.local_admin_password_only_email' => null]);
        $this->request('GET', self::SESSION, $admin)->assertUnauthorized();
        $this->assertTrue(DB::table('user_sessions')->whereNotNull('revoked_at')->exists());
    }

    public function test_password_only_setting_has_no_effect_in_staging_or_production(): void
    {
        $operator = $this->operator();
        config(['auth.local_admin_password_only_email' => $operator]);

        foreach (['staging', 'production'] as $environment) {
            app()->instance('env', $environment);
            $this->challenge($operator);
        }

        $this->assertDatabaseCount('user_sessions', 0);
    }

    private function operator(bool $operator = true, string $status = 'active', bool $withFactor = true): string
    {
        $publicId = (string) Str::ulid();
        $email = strtolower($publicId).'@example.test';
        $id = DB::table('users')->insertGetId([
            'public_id' => $publicId,
            'name' => 'Test fixture',
            'email' => $email,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'is_operator' => $operator,
            'status' => $status,
        ]);
        if ($withFactor) {
            DB::insert(
                "INSERT INTO staff_2fa_secrets (user_id, secret_encrypted, confirmed_at, recovery_hashes)
                 VALUES (?, convert_to(?, 'UTF8'), CURRENT_TIMESTAMP, ARRAY[?]::text[])",
                [$id, Crypt::encryptString(self::SECRET), password_hash(self::RECOVERY, PASSWORD_ARGON2ID)],
            );
        }

        return $email;
    }

    private function challenge(string $email): array
    {
        $cookies = $this->csrf([], login: true);
        $response = $this->request('POST', self::LOGIN, $cookies, $this->credentials($email))
            ->assertOk()->assertJsonPath('two_factor_required', true)
            ->assertJsonStructure(['challenge_id', 'expires_at'])
            ->assertCookieMissing(self::SESSION_COOKIE);
        $cookies = array_replace($cookies, $this->cookieJar($response));
        $this->assertArrayHasKey(self::CHALLENGE_COOKIE, $cookies);

        return [$response->json('challenge_id'), $this->csrf($cookies, login: true)];
    }

    private function credentials(string $email, string $password = self::PASSWORD): array
    {
        return ['email' => $email, 'password' => $password];
    }

    private function csrf(array $cookies, bool $login = false): array
    {
        $path = '/sanctum/csrf-cookie?scope=admin'.($login ? '&purpose=login' : '');
        $response = $this->request('GET', $path, $cookies)->assertNoContent();
        $updated = $this->cookieJar($response);
        $this->assertArrayHasKey('XSRF-TOKEN', $updated);

        return array_replace($cookies, $updated);
    }

    private function cookieJar(TestResponse $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $cookies;
    }

    private function request(string $method, string $path, array $cookies, array $payload = [], bool $sendCsrf = true): TestResponse
    {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://localhost',
            'HTTPS' => 'on',
        ];
        if ($sendCsrf && isset($cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $cookies['XSRF-TOKEN'];
        }

        return $this->call($method, $path, [], $cookies, [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
