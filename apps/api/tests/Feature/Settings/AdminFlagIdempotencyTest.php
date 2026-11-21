<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AdminFlagIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private const PATH = '/api/v1/admin/feature-flags';

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private const RECOVERY = 'A1B2C3D4E5F6';

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        FeatureFlag::create(['code' => 'checkout.enabled', 'description' => 'Checkout', 'default_enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        parent::tearDown();
    }

    public function test_authentication_permissions_and_csrf_are_enforced(): void
    {
        $this->send('GET')->assertUnauthorized();
        $this->send('PATCH', $this->payload())->assertUnauthorized();
        $user = $this->login();
        $this->send('GET')->assertForbidden();
        $this->send('PATCH', $this->payload())->assertForbidden();
        $this->grant($user, 'setting.view');
        $this->send('GET')->assertOk()->assertJsonPath('data.0.code', 'checkout.enabled')->assertJsonPath('data.0.overrides', []);
        $this->send('PATCH', $this->payload())->assertForbidden();
        $this->grant($user, 'setting.update');
        $this->send('PATCH', $this->payload(), csrf: false)->assertStatus(419);
    }

    public function test_replay_conflict_and_atomic_audited_override_update(): void
    {
        $user = $this->login();
        $this->grant($user, 'setting.update');
        $key = (string) Str::uuid();
        $first = $this->send('PATCH', $this->payload(), $key)->assertOk()->assertHeader('Idempotency-Replayed', 'false');
        $replay = $this->send('PATCH', $this->payload(), $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->getContent(), $replay->getContent());
        $changed = $this->payload();
        $changed['reason'] = 'Different reason';
        $this->send('PATCH', $changed, $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH');
        $this->assertDatabaseHas('feature_flags', ['code' => 'checkout.enabled', 'default_enabled' => false, 'updated_by' => $user->id]);
        $this->assertDatabaseHas('feature_flag_overrides', ['flag_code' => 'checkout.enabled', 'env' => 'local', 'enabled' => false, 'rollout_percent' => 25, 'updated_by' => $user->id]);
        $this->assertSame(1, DB::table('audit_logs')->count());
        $audit = DB::table('audit_logs')->first();
        $this->assertSame('Incident maintenance', $audit->reason);
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame('feature_flags', $audit->target_type);
        $this->assertFalse(json_decode($audit->after, true)['default_enabled']);
        $this->assertSame(26, strlen($audit->request_id));
        $this->send('PATCH', $this->payload(), (string) Str::uuid())->assertOk();
        $this->assertSame(1, DB::table('feature_flag_overrides')->count());
        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_validation_missing_uuid_unknown_flag_and_rollback(): void
    {
        $user = $this->login();
        $this->grant($user, 'setting.update');
        $this->send('PATCH', $this->payload())->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->send('PATCH', $this->payload(), (string) Str::ulid())->assertStatus(400);
        foreach (['', '    ', 'abcd', str_repeat('a', 321)] as $reason) {
            $payload = $this->payload();
            $payload['reason'] = $reason;
            $this->send('PATCH', $payload, (string) Str::uuid())->assertUnprocessable();
        }
        $this->send('PATCH', ['flags' => [], 'reason' => 'Valid reason'], (string) Str::uuid())->assertUnprocessable();
        $payload = $this->payload();
        unset($payload['flags'][0]['env']);
        $this->send('PATCH', $payload, (string) Str::uuid())->assertUnprocessable();
        $payload = $this->payload();
        $payload['flags'][0]['rollout_percent'] = 101;
        $this->send('PATCH', $payload, (string) Str::uuid())->assertUnprocessable();
        $payload = $this->payload();
        $payload['flags'][] = ['code' => 'unknown.flag', 'default_enabled' => false];
        $this->send('PATCH', $payload, (string) Str::uuid())->assertNotFound();
        $this->assertDatabaseHas('feature_flags', ['code' => 'checkout.enabled', 'default_enabled' => true, 'updated_by' => null]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_preserves_the_request_id_seen_by_the_client(): void
    {
        $user = $this->login();
        $this->grant($user, 'setting.update');
        foreach (['client-request-7', (string) Str::uuid(), str_repeat('a', 128)] as $requestId) {
            $this->send('PATCH', $this->payload(), (string) Str::uuid(), requestId: $requestId)
                ->assertOk()->assertHeader('X-Request-Id', $requestId);
            $query = DB::table('audit_logs');
            if (strlen($requestId) > 26) {
                $query->where('after->_context->request_id', $requestId);
            } else {
                $query->where('request_id', $requestId);
            }
            $this->assertSame(1, $query->count());
        }
    }

    private function payload(): array
    {
        return ['flags' => [['code' => 'checkout.enabled', 'default_enabled' => false, 'env' => 'local', 'enabled' => false, 'rollout_percent' => 25]], 'reason' => 'Incident maintenance'];
    }

    private function grant(User $user, string $name): void
    {
        $permission = Permission::create(['name' => $name, 'guard_name' => 'admin', 'group_name' => 'Settings']);
        $user->givePermissionTo($permission);
    }

    private function login(): User
    {
        $id = DB::table('users')->insertGetId(['public_id' => (string) Str::ulid(), 'name' => 'Operator', 'email' => 'flags-admin@example.test', 'is_operator' => true, 'password_hash' => password_hash('fixture-password', PASSWORD_BCRYPT, ['cost' => 4])]);
        DB::insert("INSERT INTO staff_2fa_secrets (user_id, secret_encrypted, confirmed_at, recovery_hashes) VALUES (?, convert_to(?, 'UTF8'), CURRENT_TIMESTAMP, ARRAY[?]::text[])",
            [$id, Crypt::encryptString(self::SECRET), password_hash(self::RECOVERY, PASSWORD_ARGON2ID)]);
        $this->csrf(true);
        $challenge = $this->send('POST', ['email' => 'flags-admin@example.test', 'password' => 'fixture-password'], path: '/api/v1/admin/auth/login')->assertOk()->json('challenge_id');
        $this->csrf(true);
        $this->send('POST', ['challenge_id' => $challenge, 'code' => self::RECOVERY], path: '/api/v1/admin/auth/2fa/verify')->assertOk();
        $this->csrf();

        return User::query()->findOrFail($id);
    }

    private function csrf(bool $login = false): void
    {
        $this->send('GET', path: '/sanctum/csrf-cookie?scope=admin'.($login ? '&purpose=login' : ''))->assertNoContent();
    }

    private function send(string $method, array $payload = [], ?string $key = null, bool $csrf = true, string $path = self::PATH, ?string $requestId = null): TestResponse
    {
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on'];
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        if ($key !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        if ($requestId !== null) {
            $headers['HTTP_X_REQUEST_ID'] = $requestId;
        }
        $response = $this->call($method, $path, [], $this->cookies, [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if (! $cookie->isCleared()) {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
