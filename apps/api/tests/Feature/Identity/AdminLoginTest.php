<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Security\StaffTwoFactor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['surfaces.admin_origin' => 'https://admin.localhost', 'sanctum.stateful' => ['localhost', 'admin.localhost']]);
    }

    public function test_auth_inputs_reject_unknown_fields_before_credentials(): void
    {
        $cookies = $this->csrf([], login: true);
        $this->request('POST', self::LOGIN, $cookies, ['email' => 'nobody@example.test', 'password' => 'password', 'role' => 'admin'])
            ->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/role')->assertJsonPath('errors.0.code', 'unknown_field');
        $this->request('POST', self::VERIFY.'?extra=1', $cookies, ['challenge_id' => 'invalid', 'code' => '123456'])
            ->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/extra')->assertJsonPath('errors.0.code', 'unknown_field');
    }

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
        DB::table('users')->where('email', $operator)->update(['timezone' => 'Asia/Makassar', 'email_verified_at' => '2026-11-17 04:12:33+00']);
        [$challenge, $cookies] = $this->challenge($operator);
        $this->request('GET', self::SESSION, $cookies)->assertUnauthorized();
        $code = TOTP::createFromSecret(self::SECRET)->at(now()->getTimestamp());

        $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => 'wrong', 'code' => $code])->assertUnauthorized();
        $wrongCode = $code === '000000' ? '999999' : '000000';
        $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => $challenge, 'code' => $wrongCode])->assertUnauthorized();
        $response = $this->request('POST', self::VERIFY, $cookies, ['challenge_id' => $challenge, 'code' => $code])
            ->assertOk()->assertJsonPath('authenticated', true)->assertJsonPath('user.email', $operator)
            ->assertJsonPath('user.timezone', 'Asia/Makassar')->assertJsonPath('user.email_verified_at', '2026-11-17T04:12:33.000Z')
            ->assertHeader('X-Displayed-Timezone', 'Asia/Makassar');
        $admin = $this->cookieJar($response);
        $this->assertArrayHasKey(self::SESSION_COOKIE, $admin);
        $this->request('GET', self::SESSION, $admin)->assertOk()->assertJsonPath('user.email', $operator);
        $this->request('GET', self::SESSION, ['elzara_web_session' => $admin[self::SESSION_COOKIE]])->assertUnauthorized();
        $replay = ['challenge_id' => $challenge, 'code' => $code];
        $this->request('POST', self::VERIFY, $cookies, $replay)->assertStatus(419);
        $this->request('POST', self::VERIFY, $this->csrf($cookies, login: true), $replay)->assertUnauthorized();

        $admin = $this->csrf($admin);
        $this->request('POST', self::LOGOUT, $admin, [], false)->assertStatus(419);
        $this->request('POST', self::LOGOUT, $admin)->assertNoContent()->assertHeader('X-Displayed-Timezone', 'Asia/Makassar');
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

    public function test_disabled_two_factor_allows_non_production_login_without_enrollment(): void
    {
        Log::spy();
        config(['auth.admin_2fa_enabled' => false]);

        foreach (['local', 'staging'] as $environment) {
            app()->instance('env', $environment);
            $operator = $this->operator(withFactor: false);
            $response = $this->request('POST', self::LOGIN, $this->csrf([], login: true), $this->credentials($operator))
                ->assertOk()->assertJsonPath('two_factor_required', false)->assertJsonPath('authenticated', true)
                ->assertJsonMissingPath('challenge_id');
            $admin = $this->cookieJar($response);
            $this->assertArrayHasKey(self::SESSION_COOKIE, $admin);
            $this->request('GET', self::SESSION, $admin)->assertOk()->assertJsonPath('user.email', $operator);

            app()->instance('env', 'production');
            $this->request('GET', self::SESSION, $admin)->assertUnauthorized();
        }

        Log::shouldHaveReceived('warning')->with('Admin two-factor authentication bypass is active.', \Mockery::type('array'))->twice();
        $this->assertDatabaseCount('staff_2fa_secrets', 0);
    }

    public function test_disabled_two_factor_does_not_bypass_credentials_or_operator_checks(): void
    {
        app()->instance('env', 'local');
        config(['auth.admin_2fa_enabled' => false]);
        $operator = $this->operator(withFactor: false);
        $customer = $this->operator(false, withFactor: false);
        $suspended = $this->operator(status: 'suspended', withFactor: false);
        $cookies = $this->csrf([], login: true);

        $this->request('POST', self::LOGIN, $cookies, $this->credentials($operator, 'wrong-password'))->assertUnauthorized();
        $this->request('POST', self::LOGIN, $cookies, $this->credentials('missing@example.test'))->assertUnauthorized();
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($customer))->assertForbidden();
        $this->request('POST', self::LOGIN, $cookies, $this->credentials($suspended))->assertForbidden();
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_disabled_two_factor_is_ignored_in_production(): void
    {
        app()->instance('env', 'production');
        config(['auth.admin_2fa_enabled' => false]);
        $this->challenge($this->operator());
        $unenrolled = $this->operator(withFactor: false);
        $this->request('POST', self::LOGIN, $this->csrf([], login: true), $this->credentials($unenrolled))->assertForbidden();
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_two_factor_is_enabled_by_default_in_local_environment(): void
    {
        app()->instance('env', 'local');
        $this->assertTrue(config('auth.admin_2fa_enabled'));
        $this->challenge($this->operator());
        $unenrolled = $this->operator(withFactor: false);
        $this->request('POST', self::LOGIN, $this->csrf([], login: true), $this->credentials($unenrolled))->assertForbidden();
        $this->assertDatabaseCount('user_sessions', 0);
    }

    public function test_enrollment_stores_ten_argon2id_recovery_hashes(): void
    {
        $email = $this->operator(withFactor: false);
        $user = User::query()->where('email', $email)->firstOrFail();
        $otp = TOTP::createFromSecret(self::SECRET);
        $codes = app(StaffTwoFactor::class)->enroll($user, $otp, $otp->at(now()->getTimestamp()));

        $this->assertNotNull($codes);
        $this->assertCount(10, $codes);
        $hashes = $this->recoveryHashes($email);
        $this->assertCount(10, $hashes);
        foreach ($codes as $index => $code) {
            $this->assertSame('argon2id', password_get_info($hashes[$index])['algoName']);
            $this->assertTrue(password_verify($code, $hashes[$index]));
            $this->assertNotContains(hash('sha256', $code), $hashes);
        }
    }

    public function test_migrated_legacy_recovery_code_completes_login_only_once(): void
    {
        $operator = $this->operator();
        $modern = password_hash('112233AABBCC', PASSWORD_ARGON2ID);
        $this->setRecoveryHashes($operator, [hash('sha256', self::RECOVERY), $modern]);
        $empty = $this->operator();
        $this->setRecoveryHashes($empty, []);

        $migration = require app_path('Modules/Identity/Infrastructure/Database/Migrations/0075_Identity_wrap_legacy_recovery_hashes.php');
        $migration->up();

        $hashes = $this->recoveryHashes($operator);
        $this->assertCount(2, $hashes);
        $this->assertStringStartsWith('legacy-sha256$', $hashes[0]);
        $wrapped = substr($hashes[0], strlen('legacy-sha256$'));
        $this->assertSame('argon2id', password_get_info($wrapped)['algoName']);
        $this->assertTrue(password_verify(hash('sha256', self::RECOVERY), $wrapped));
        $this->assertSame($modern, $hashes[1]);
        $this->assertSame([], $this->recoveryHashes($empty));
        $migration->up();
        $this->assertSame($hashes, $this->recoveryHashes($operator));

        [$challenge, $cookies] = $this->challenge($operator);
        $this->request('POST', self::VERIFY, $cookies, [
            'challenge_id' => $challenge, 'code' => strtolower(self::RECOVERY),
        ])->assertOk()->assertJsonPath('authenticated', true);
        $this->assertSame([$modern], $this->recoveryHashes($operator));

        [$challenge, $cookies] = $this->challenge($operator);
        $this->request('POST', self::VERIFY, $cookies, [
            'challenge_id' => $challenge, 'code' => self::RECOVERY,
        ])->assertUnauthorized();
    }

    public function test_bare_sha256_and_non_argon2id_wrappers_are_rejected(): void
    {
        $operator = $this->operator();
        $hashes = [
            hash('sha256', self::RECOVERY),
            'legacy-sha256$'.password_hash(hash('sha256', self::RECOVERY), PASSWORD_BCRYPT, ['cost' => 4]),
        ];
        $this->setRecoveryHashes($operator, $hashes);
        [$challenge, $cookies] = $this->challenge($operator);
        $this->request('POST', self::VERIFY, $cookies, [
            'challenge_id' => $challenge, 'code' => self::RECOVERY,
        ])->assertUnauthorized();
        $this->assertSame($hashes, $this->recoveryHashes($operator));
    }

    private function recoveryHashes(string $email): array
    {
        $factor = DB::table('staff_2fa_secrets')
            ->where('user_id', DB::table('users')->where('email', $email)->value('id'))
            ->selectRaw('to_json(recovery_hashes) AS recovery')->first();

        return json_decode($factor->recovery, true, 8, JSON_THROW_ON_ERROR);
    }

    private function setRecoveryHashes(string $email, array $hashes): void
    {
        $placeholders = implode(', ', array_fill(0, count($hashes), '?'));
        DB::update(
            "UPDATE staff_2fa_secrets SET recovery_hashes = ARRAY[{$placeholders}]::text[] WHERE user_id = ?",
            [...$hashes, DB::table('users')->where('email', $email)->value('id')],
        );
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
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z\z/', $response->json('expires_at'));
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
            'HTTP_HOST' => 'admin.localhost',
            'HTTP_ORIGIN' => 'https://admin.localhost',
            'HTTPS' => 'on',
        ];
        if ($sendCsrf && isset($cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $cookies['XSRF-TOKEN'];
        }

        $host = str_contains($path, '/admin/') || str_contains($path, 'scope=admin') ? 'admin.localhost' : 'localhost';
        $headers['HTTP_HOST'] = $host;
        $headers['HTTP_ORIGIN'] = 'https://'.$host;

        return $this->call($method, 'https://'.$host.$path, [], $cookies, [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
