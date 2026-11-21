<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class IdempotencyGuardTest extends TestCase
{
    private const PATH = '/api/v1/__idempotency';

    private const KEY = 'a8d4bccc-56a7-40ad-a0f1-a4761fc6886f'; // gitleaks:allow

    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = 'idem_feature_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$schema}");
        $this->schema = $schema;
        DB::statement("SET search_path TO {$schema}");
        (require app_path('Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php'))->up();
        (require app_path('Platform/Infrastructure/Database/Migrations/0080_Platform_add_idempotency_response_snapshot.php'))->up();
        Schema::create('idempotency_effects', static function (Blueprint $table): void {
            $table->id();
        });
        $operation = static function (Request $request): JsonResponse {
            $id = DB::table('idempotency_effects')->insertGetId([]);

            if ($request->header('X-Fixture-Mode') === 'validate') {
                $request->validate(['required_field' => 'required']);
            }
            if ($request->header('X-Fixture-Mode') === 'fail') {
                return response()->json(['message' => 'Temporary error'], 503);
            }
            if ($request->header('X-Fixture-Mode') === 'reject') {
                return response()->json(['message' => 'Fixture rejected'], 422);
            }

            return response()->json(['order' => $id, 'fixture' => true], 201, ['Location' => "/api/v1/orders/{$id}"])
                ->cookie('idempotency_fixture', 'first-response');
        };
        Route::post(self::PATH, $operation)->middleware('idempotency:createOrders,web,86400');
        Route::post(self::PATH.'/other-path', $operation)->middleware('idempotency:createOrders,web,86400');
        Route::post(self::PATH.'/other-operation', $operation)->middleware('idempotency:otherOperation,web,86400');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schema !== null) {
                DB::statement('RESET search_path');
                DB::statement("DROP SCHEMA {$this->schema} CASCADE");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_replay_preserves_original_body_status_and_headers_and_conflict_has_no_effect(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'web');
        $first = $this->postJson(self::PATH, ['b' => 2, 'a' => 1], $this->headers('fixture-first'))
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertHeader('X-Request-Id', 'fixture-first');
        $this->assertNotEmpty($first->headers->getCookies());
        $replay = $this->postJson(self::PATH, ['a' => 1, 'b' => 2], $this->headers('fixture-retry'))
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertHeader('Location', $first->headers->get('Location'))
            ->assertHeader('Content-Type', $first->headers->get('Content-Type'))
            ->assertHeader('X-Request-Id', 'fixture-first');
        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame([], $replay->headers->getCookies());

        $this->postJson(self::PATH, ['a' => 1, 'b' => 3], $this->headers())->assertConflict();
        $this->postJson(self::PATH.'?mode=other', ['a' => 1, 'b' => 2], $this->headers())->assertConflict();
        $this->postJson(self::PATH.'/other-path', ['a' => 1, 'b' => 2], $this->headers())->assertConflict();
        $this->assertSame(1, DB::table('idempotency_effects')->count());

        $this->postJson(self::PATH.'/other-operation', ['a' => 1, 'b' => 2], $this->headers())
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->actingAs(new GenericUser(['id' => 2]), 'web');
        $this->postJson(self::PATH, ['a' => 1, 'b' => 2], $this->headers())
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->assertSame(3, DB::table('idempotency_effects')->count());
    }

    public function test_authentication_and_key_validation_precede_the_effect(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'admin');
        $this->postJson(self::PATH, [], $this->headers())->assertUnauthorized();
        $this->actingAs(new GenericUser(['id' => 1]), 'web');
        $this->postJson(self::PATH)->assertBadRequest()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
        $this->postJson(self::PATH, [], ['Idempotency-Key' => 'invalid'])
            ->assertBadRequest()->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
        $this->postJson(self::PATH, ['value' => str_repeat('x', 262144)], $this->headers())->assertStatus(413);
        $this->assertSame(0, DB::table('idempotency_effects')->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }

    public function test_recoverable_failures_leave_no_claim_or_effect_and_retry_succeeds(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'web');
        $this->postFixtureOutcome('fail')->assertStatus(503);
        $this->assertSame(0, DB::table('idempotency_effects')->count());
        $this->assertSame(0, DB::table('idempotency_keys')->count());

        $this->postFixtureOutcome('success')->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->assertSame(1, DB::table('idempotency_effects')->count());
        $this->assertSame('succeeded', DB::table('idempotency_keys')->value('status'));
    }

    public function test_client_errors_are_replayed_without_preserving_their_database_effects(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'web');

        foreach (['reject', 'validate'] as $mode) {
            $body = ['fixture_mode' => $mode];
            $key = match ($mode) {
                'reject' => self::KEY,
                'validate' => 'a8d4bccc-56a7-40ad-a0f1-a4761fc68870',
            };
            $headers = ['Idempotency-Key' => $key, 'X-Request-Id' => 'client-error-request'];
            $first = $this->postJson(self::PATH, $body, $headers + ['X-Fixture-Mode' => $mode])
                ->assertUnprocessable()->assertHeader('Idempotency-Replayed', 'false');
            $replay = $this->postJson(self::PATH, $body, $headers)->assertUnprocessable()
                ->assertHeader('Idempotency-Replayed', 'true')
                ->assertHeader('X-Request-Id', 'client-error-request')
                ->assertHeader('Content-Type', $first->headers->get('Content-Type'));
            $this->assertSame($first->getContent(), $replay->getContent());
            $this->assertSame(0, DB::table('idempotency_effects')->count());
        }

        $this->assertSame(2, DB::table('idempotency_keys')->where('status', 'failed')->count());
    }

    public function test_expired_claim_can_run_again_and_replaces_the_saved_response(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]), 'web');
        $first = $this->postJson(self::PATH, ['a' => 1], $this->headers())->assertCreated();
        DB::table('idempotency_keys')->update(['expires_at' => now()->subMinute()]);

        $second = $this->postJson(self::PATH, ['a' => 2], $this->headers())
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $this->assertNotSame($first->getContent(), $second->getContent());
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $this->assertSame(2, DB::table('idempotency_effects')->count());
    }

    private function postFixtureOutcome(string $mode): TestResponse
    {
        return $this->postJson(self::PATH, ['a' => 1], $this->headers() + ['X-Fixture-Mode' => $mode]);
    }

    /** @return array<string, string> */
    private function headers(string $requestId = 'fixture-request'): array
    {
        return ['Idempotency-Key' => self::KEY, 'X-Request-Id' => $requestId];
    }
}
