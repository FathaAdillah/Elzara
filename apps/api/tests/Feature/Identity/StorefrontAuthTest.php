<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Infrastructure\Http\SocialAccountResolver;
use App\Modules\Identity\Infrastructure\Mail\OneTimeCodeMail;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class StorefrontAuthTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'correct horse battery';

    private const WEB_COOKIE = 'elzara_web_session';

    private const ADMIN_COOKIE = 'elzara_admin_session';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush();
    }

    public function test_register_requires_csrf_opens_web_session_and_records_event(): void
    {
        $email = strtolower((string) Str::ulid()).'@example.test';
        $payload = ['name' => 'Rani', 'email' => $email, 'password' => self::PASSWORD];

        $jar = $this->browser();
        $this->send($jar, 'POST', '/api/v1/auth/register', $payload, csrf: false)->assertStatus(419);

        $this->send($jar, 'POST', '/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('user.email', $email)
            ->assertJsonPath('user.email_verified_at', null)
            ->assertCookie(self::WEB_COOKIE)
            ->assertCookieMissing(self::ADMIN_COOKIE);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->is_operator);
        $this->assertTrue(DB::table('outbox_events')->where('event_name', 'UserRegistered')->where('aggregate_id', $user->getKey())->exists());
        Mail::assertSent(OneTimeCodeMail::class, fn (OneTimeCodeMail $mail) => $mail->purpose === 'verify_email' && $mail->hasTo($email));

        $this->send($jar, 'GET', '/api/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonPath('meta.has_more', false);

        $this->sendFresh('POST', '/api/v1/auth/register', $payload)->assertStatus(409);
    }

    public function test_login_accepts_any_active_account_and_stays_off_the_admin_guard(): void
    {
        $operator = $this->account(operator: true);
        $jar = $this->browser();
        $this->send($jar, 'POST', '/api/v1/auth/login', ['email' => $operator, 'password' => 'wrong'])->assertUnauthorized();
        $this->send($jar, 'POST', '/api/v1/auth/login', ['email' => $operator, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.email', $operator)
            ->assertCookie(self::WEB_COOKIE)
            ->assertCookieMissing(self::ADMIN_COOKIE);
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
        $this->send($jar, 'GET', '/api/v1/admin/auth/session')->assertUnauthorized();

        foreach (['suspended', 'deleted'] as $status) {
            $email = $this->account(status: $status);
            $this->sendFresh('POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertUnauthorized();
        }
    }

    public function test_logout_revokes_session_and_suspension_ends_an_open_session(): void
    {
        $email = $this->account();
        $jar = $this->signedIn($email);
        $this->send($jar, 'POST', '/api/v1/auth/logout')->assertNoContent();
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $this->id($email))->whereNull('revoked_at')->count());
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertUnauthorized();

        $jar = $this->signedIn($email);
        DB::table('users')->where('email', $email)->update(['status' => 'suspended']);
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertUnauthorized();
    }

    public function test_otp_is_argon2id_single_use_and_anti_enumeration(): void
    {
        $email = $this->account();
        $jar = $this->browser();
        $unknown = $this->send($jar, 'POST', '/api/v1/auth/otp/request', ['email' => 'nobody@example.test', 'channel' => 'email', 'purpose' => 'login'])
            ->assertOk()->assertJsonPath('sent', true);
        $known = $this->send($jar, 'POST', '/api/v1/auth/otp/request', ['email' => $email, 'channel' => 'email', 'purpose' => 'verify_email'])
            ->assertOk()->assertJsonPath('sent', true);
        $this->assertSame(array_keys($unknown->json()), array_keys($known->json()));
        Mail::assertSentCount(1);
        $this->assertStringStartsWith('$argon2id$', (string) DB::table('otp_challenges')->where('user_id', $this->id($email))->value('code_hash'));

        $code = $this->mailedSecret('verify_email');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->send($jar, 'POST', '/api/v1/auth/otp/verify', ['email' => $email, 'purpose' => 'verify_email', 'code' => $wrong])->assertUnauthorized();
        $this->send($jar, 'POST', '/api/v1/auth/otp/verify', ['email' => $email, 'purpose' => 'verify_email', 'code' => $code])
            ->assertOk()->assertJsonPath('verified', true);
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertUnauthorized();
        $this->assertNotNull(DB::table('users')->where('email', $email)->value('email_verified_at'));
        $this->send($jar, 'POST', '/api/v1/auth/otp/verify', ['email' => $email, 'purpose' => 'verify_email', 'code' => $code])->assertUnauthorized();

        $this->send($jar, 'POST', '/api/v1/auth/otp/request', ['email' => $email, 'channel' => 'email', 'purpose' => 'login'])->assertOk();
        $this->send($jar, 'POST', '/api/v1/auth/otp/verify', ['email' => $email, 'purpose' => 'login', 'code' => $this->mailedSecret('login')])
            ->assertOk()->assertCookie(self::WEB_COOKIE);
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');

        $this->send($jar, 'POST', '/api/v1/auth/otp/request', ['email' => $email, 'channel' => 'sms', 'purpose' => 'verify_phone'])->assertStatus(422);
    }

    public function test_password_reset_is_single_use_and_revokes_every_session(): void
    {
        $email = $this->account();
        $old = $this->signedIn($email);
        $jar = $this->browser();
        $this->send($jar, 'POST', '/api/v1/auth/password/reset-request', ['email' => 'nobody@example.test'])->assertOk()->assertJsonPath('sent', true);
        Mail::assertNothingSent();
        $this->send($jar, 'POST', '/api/v1/auth/password/reset-request', ['email' => $email])->assertOk()->assertJsonPath('sent', true);
        $token = $this->mailedSecret('reset_password_link');

        $this->send($jar, 'POST', '/api/v1/auth/password/reset-confirm', ['token' => $token.'x', 'password' => 'brand new secret'])->assertUnauthorized();
        $this->send($jar, 'POST', '/api/v1/auth/password/reset-confirm', ['token' => $token, 'password' => 'brand new secret'])
            ->assertOk()->assertJsonPath('reset', true);
        $this->send($jar, 'POST', '/api/v1/auth/password/reset-confirm', ['token' => $token, 'password' => 'another secret'])->assertUnauthorized();

        $this->send($old, 'GET', '/api/v1/me/sessions')->assertUnauthorized();
        $this->sendFresh('POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertUnauthorized();
        $this->sendFresh('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'brand new secret'])->assertOk();
    }

    public function test_sessions_are_listed_and_revoked_only_by_their_owner(): void
    {
        $alice = $this->account();
        $bob = $this->account();
        $aliceLaptop = $this->signedIn($alice);
        $alicePhone = $this->signedIn($alice);
        $this->signedIn($bob);
        $bobSession = (string) DB::table('user_sessions')->where('user_id', $this->id($bob))->value('session_hash');

        $sessions = $this->send($aliceLaptop, 'GET', '/api/v1/me/sessions?limit=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', true);
        $this->send($aliceLaptop, 'GET', '/api/v1/me/sessions?cursor='.$sessions->json('meta.next_cursor'))->assertOk()->assertJsonCount(1, 'data');

        $this->send($aliceLaptop, 'DELETE', '/api/v1/me/sessions/'.$bobSession)->assertNotFound();
        $this->assertNull(DB::table('user_sessions')->where('session_hash', $bobSession)->value('revoked_at'));

        $phone = collect($this->send($aliceLaptop, 'GET', '/api/v1/me/sessions')->json('data'))->firstWhere('is_current', false);
        $this->send($aliceLaptop, 'DELETE', '/api/v1/me/sessions/'.$phone['id'])->assertNoContent();
        $this->send($alicePhone, 'GET', '/api/v1/me/sessions')->assertUnauthorized();
        $this->send($aliceLaptop, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_social_redirect_carries_state_and_callback_rejects_a_forged_state(): void
    {
        config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
        $jar = $this->browser();
        $redirect = $this->send($jar, 'GET', '/api/v1/auth/social/google/redirect')->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/', (string) $redirect->headers->get('Location'));
        $this->assertStringContainsString('state=', (string) $redirect->headers->get('Location'));

        $this->send($jar, 'GET', '/api/v1/auth/social/google/callback?code=abc&state=forged')->assertUnauthorized();
        $this->send($jar, 'GET', '/api/v1/auth/social/github/redirect')->assertNotFound();
    }

    public function test_social_callback_creates_account_identity_and_web_session(): void
    {
        $social = (new SocialiteUser)->setRaw(['email_verified' => true])->map([
            'id' => 'g-'.Str::random(10), 'name' => 'Sinta', 'email' => strtolower((string) Str::ulid()).'@example.test',
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $jar = $this->browser();
        $this->send($jar, 'GET', '/api/v1/auth/social/google/callback?code=abc&state=xyz')
            ->assertRedirect(url('/account'))->assertCookie(self::WEB_COOKIE);
        $user = User::query()->where('email', $social->getEmail())->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(DB::table('user_identities')->where('provider', 'google')->where('provider_user_id', $social->getId())->where('user_id', $user->getKey())->exists());
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_social_linking_requires_a_verified_email_or_a_signed_in_owner(): void
    {
        $resolver = app(SocialAccountResolver::class);
        $unverified = $this->account();
        $this->assertConflict(fn () => $resolver->resolve('google', 'g-1', $unverified, true, 'A', null));

        $verified = $this->account(verified: true);
        $this->assertConflict(fn () => $resolver->resolve('google', 'g-2', $verified, false, 'B', null));
        $this->assertSame($this->id($verified), (int) $resolver->resolve('google', 'g-3', $verified, true, 'B', null)->getKey());
        $this->assertSame($this->id($verified), (int) $resolver->resolve('google', 'g-3', null, false, null, null)->getKey());

        $owner = User::query()->findOrFail($this->id($unverified));
        $this->assertSame($owner->getKey(), $resolver->resolve('x', 'x-1', null, false, 'A', $owner)->getKey());
        $this->assertConflict(fn () => $resolver->resolve('google', 'g-3', null, false, null, $owner));

        $fresh = $resolver->resolve('x', 'x-2', null, false, 'No Email', null);
        $this->assertStringEndsWith('@users.invalid', $fresh->email);
        $this->assertFalse((bool) $fresh->is_operator);
    }

    public function test_login_lockout_counts_failures_per_account_and_relogin_replaces_the_device_row(): void
    {
        $email = $this->account();
        $jar = $this->browser();
        for ($i = 0; $i < 10; $i++) {
            $this->send($jar, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnauthorized();
        }
        $this->send($jar, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertStatus(429);

        $other = $this->account();
        $jar = $this->signedIn($other);
        $this->send($jar, 'POST', '/api/v1/auth/login', ['email' => $other, 'password' => self::PASSWORD])->assertOk();
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, DB::table('user_sessions')->where('user_id', $this->id($other))->whereNull('revoked_at')->count());
    }

    public function test_mailbox_owner_proving_email_by_otp_evicts_the_unverified_registrant(): void
    {
        $email = $this->account();
        $registrant = $this->signedIn($email);

        $owner = $this->browser();
        $this->send($owner, 'POST', '/api/v1/auth/otp/request', ['email' => $email, 'channel' => 'email', 'purpose' => 'login'])->assertOk();
        $this->send($owner, 'POST', '/api/v1/auth/otp/verify', ['email' => $email, 'purpose' => 'login', 'code' => $this->mailedSecret('login')])->assertOk();

        $this->assertNull(DB::table('users')->where('email', $email)->value('password_hash'));
        $this->assertNotNull(DB::table('users')->where('email', $email)->value('email_verified_at'));
        $this->send($registrant, 'GET', '/api/v1/me/sessions')->assertUnauthorized();
        $this->sendFresh('POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertUnauthorized();
        $this->send($owner, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_reset_or_staff_accounts_keep_their_password_when_email_is_verified_later(): void
    {
        $email = $this->account();
        $this->sendFresh('POST', '/api/v1/auth/password/reset-request', ['email' => $email])->assertOk();
        $this->sendFresh('POST', '/api/v1/auth/password/reset-confirm', ['token' => $this->mailedSecret('reset_password_link'), 'password' => 'brand new secret'])->assertOk();
        $this->assertNotNull(DB::table('users')->where('email', $email)->value('email_verified_at'));

        $operator = $this->account(operator: true);
        $staffSession = $this->signedIn($operator);
        $this->sendFresh('POST', '/api/v1/auth/otp/request', ['email' => $operator, 'channel' => 'email', 'purpose' => 'login'])->assertOk();
        $this->sendFresh('POST', '/api/v1/auth/otp/verify', ['email' => $operator, 'purpose' => 'login', 'code' => $this->mailedSecret('login')])->assertOk();

        $this->assertNotNull(DB::table('users')->where('email', $operator)->value('password_hash'));
        $this->send($staffSession, 'GET', '/api/v1/me/sessions')->assertOk();
        $this->sendFresh('POST', '/api/v1/auth/login', ['email' => $operator, 'password' => self::PASSWORD])->assertOk();
    }

    public function test_registrant_verifying_in_their_own_session_keeps_password_and_session(): void
    {
        $email = strtolower((string) Str::ulid()).'@example.test';
        $jar = $this->browser();
        $this->send($jar, 'POST', '/api/v1/auth/register', ['name' => 'Rina', 'email' => $email, 'password' => self::PASSWORD])->assertCreated();
        $this->send($jar, 'POST', '/api/v1/auth/otp/verify', ['email' => $email, 'purpose' => 'verify_email', 'code' => $this->mailedSecret('verify_email')])->assertOk();

        $this->assertNotNull(DB::table('users')->where('email', $email)->value('password_hash'));
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_strict_input_and_password_byte_limit(): void
    {
        $jar = $this->browser();
        $email = strtolower((string) Str::ulid()).'@example.test';
        $this->send($jar, 'POST', '/api/v1/auth/register', ['name' => 'Rina', 'email' => $email, 'password' => self::PASSWORD, 'is_admin' => true])
            ->assertUnprocessable()->assertJsonPath('errors.0.pointer', '/is_admin');
        $this->send($jar, 'POST', '/api/v1/auth/register', ['name' => 'Rina', 'email' => $email, 'password' => str_repeat('é', 37)])
            ->assertUnprocessable();
        $this->assertFalse(DB::table('users')->where('email', $email)->exists());
    }

    public function test_social_sign_in_on_a_shared_browser_switches_account_instead_of_linking(): void
    {
        $alice = $this->account(verified: true);
        $jar = $this->signedIn($alice);
        $social = (new SocialiteUser)->setRaw(['email_verified' => true])->map([
            'id' => 'g-'.Str::random(10), 'name' => 'Bob', 'email' => strtolower((string) Str::ulid()).'@example.test',
        ]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($social);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->send($jar, 'GET', '/api/v1/auth/social/google/callback?code=abc&state=xyz')->assertRedirect(url('/account'));

        $owner = DB::table('user_identities')->where('provider_user_id', $social->getId())->value('user_id');
        $this->assertNotSame($this->id($alice), (int) $owner);
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $this->id($alice))->whereNull('revoked_at')->count());
        $this->send($jar, 'GET', '/api/v1/me/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    private function assertConflict(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected HTTP 409.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
    }

    private function account(bool $operator = false, string $status = 'active', bool $verified = false): string
    {
        $publicId = (string) Str::ulid();
        $email = strtolower($publicId).'@example.test';
        DB::table('users')->insert([
            'public_id' => $publicId,
            'name' => 'Test fixture',
            'email' => $email,
            'email_verified_at' => $verified ? now() : null,
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'is_operator' => $operator,
            'status' => $status,
        ]);

        return $email;
    }

    private function id(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function mailedSecret(string $purpose): string
    {
        $secret = null;
        Mail::assertSent(OneTimeCodeMail::class, function (OneTimeCodeMail $mail) use ($purpose, &$secret): bool {
            if ($mail->purpose === $purpose) {
                $secret = $mail->secret;
            }

            return $mail->purpose === $purpose;
        });

        return (string) $secret;
    }

    /** @return array<string, string> */
    private function signedIn(string $email): array
    {
        $jar = $this->browser();
        $this->send($jar, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])->assertOk();

        return $jar;
    }

    /** @return array<string, string> */
    private function browser(): array
    {
        $jar = [];
        $this->send($jar, 'GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->assertArrayHasKey('XSRF-TOKEN', $jar);

        return $jar;
    }

    private function sendFresh(string $method, string $path, array $payload = []): TestResponse
    {
        $jar = $this->browser();

        return $this->send($jar, $method, $path, $payload);
    }

    /** @param array<string, string> $jar */
    private function send(array &$jar, string $method, string $path, array $payload = [], bool $csrf = true): TestResponse
    {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost',
            'HTTP_ORIGIN' => 'https://localhost',
            'HTTPS' => 'on',
        ];
        if ($csrf && isset($jar['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $jar['XSRF-TOKEN'];
        }

        $response = $this->call($method, $path, [], $jar, [], $headers, $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($jar[$cookie->getName()]);
            } else {
                $jar[$cookie->getName()] = (string) $cookie->getValue();
            }
        }
        $this->app['auth']->forgetGuards();

        return $response;
    }
}
