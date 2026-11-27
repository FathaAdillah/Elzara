<?php

declare(strict_types=1);

namespace Tests\Feature\Sourcing;

use App\Modules\Identity\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class ProductRequestTest extends TestCase
{
    use DatabaseTransactions;

    private const CREATE = '/api/v1/product-requests';

    private const MINE = '/api/v1/me/product-requests';

    private const QUEUE = '/api/v1/admin/product-requests';

    private array $cookies = [];

    public function test_customer_submits_once_and_gets_a_private_receipt_with_a_weekday_sla(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-26 18:00:00 UTC'));
        $customer = $this->login();
        $input = [
            'title' => 'Custom Moon Charm',
            'quantity' => 2,
            'target_price' => ['amount_minor' => 85000, 'currency' => 'IDR'],
            'target_url' => 'https://example.com/items/charm',
            'origin_country' => 'JP',
            'preferred_mode' => 'preorder',
            'notes' => 'Silver only',
        ];
        $key = (string) Str::uuid();
        $first = $this->send('POST', self::CREATE, $input, $key)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'false')->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson($input)->assertJsonPath('status', 'new')
            ->assertJsonPath('sla_due_at', '2026-11-30T18:00:00.000Z');
        $id = $first->json('id');
        $this->assertMatchesRegularExpression('/^prq_[0-9A-HJKMNP-TV-Z]{26}$/', $id);
        $this->send('POST', self::CREATE, $input, $key)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first->json());
        $this->send('POST', self::CREATE, ['quantity' => 3] + $input, $key)->assertConflict();
        $this->assertDatabaseCount('product_requests', 1);
        $this->assertDatabaseMissing('orders', ['user_id' => $customer->id]);
        $this->assertDatabaseHas('product_requests', ['code' => $id, 'user_id' => $customer->id, 'reference_media_id' => null]);
        $this->send('GET', self::MINE)->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.customer_email')->assertJsonMissingPath('data.0.user_id');
    }

    public function test_public_access_forged_fields_and_invalid_inputs_are_rejected(): void
    {
        $valid = ['title' => 'Custom Moon Charm', 'quantity' => 1];
        $key = (string) Str::uuid();
        $this->send('POST', self::CREATE, $valid, $key)->assertUnauthorized();
        $this->send('GET', self::MINE)->assertUnauthorized();
        $this->login();
        $this->send('POST', self::CREATE, $valid)->assertStatus(400);
        $this->send('POST', self::CREATE, $valid, $key, csrf: false)->assertStatus(419);
        foreach ([
            ['title' => 'ab'], ['quantity' => 0], ['quantity' => 1.5], ['quantity' => '2'],
            ['target_price' => []], ['target_price' => ['currency' => 'USD', 'amount_minor' => 1]],
            ['target_price' => ['currency' => 'IDR', 'amount_minor' => 1.5]], ['target_price' => ['amount_minor' => 1]],
            ['target_url' => 'javascript:alert(1)'], ['origin_country' => 'JPN'], ['preferred_mode' => 'request'],
            ['user_id' => 42], ['status' => 'accepted'], ['reference_media_id' => 42],
        ] as $bad) {
            $this->send('POST', self::CREATE, $bad + $valid, (string) Str::uuid())->assertUnprocessable();
        }
        $this->assertDatabaseCount('product_requests', 0);
        $this->send('POST', self::CREATE, $valid, $key)->assertCreated()->assertJsonPath('target_price', null);
    }

    public function test_history_is_owner_scoped_and_staff_queue_requires_sourcing_permission(): void
    {
        $buyer = $this->login();
        $first = $this->submit('Private buyer request');
        $second = $this->submit('Another private request');
        $page = $this->send('GET', self::MINE.'?limit=1')->assertOk()->assertJsonPath('data.0.id', $second);
        $cursor = $page->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->send('GET', self::MINE.'?limit=1&cursor='.$cursor)->assertOk()->assertJsonPath('data.0.id', $first);
        $this->send('GET', self::QUEUE)->assertUnauthorized();

        $otherBuyer = $this->login();
        $this->send('GET', self::MINE)->assertOk()->assertJsonCount(0, 'data');
        $this->send('GET', self::MINE.'?cursor='.$cursor)->assertStatus(400);
        $other = $this->submit('Request from another owner');
        $this->send('GET', self::MINE)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);

        $staff = $this->login(admin: true);
        $this->send('GET', self::QUEUE)->assertForbidden();
        $staff->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'sourcing.view', 'guard_name' => 'admin'], ['group_name' => 'sourcing']));
        $this->send('GET', self::QUEUE.'?limit=1')->assertOk()->assertJsonPath('data.0.id', $other);
        $this->send('GET', self::QUEUE.'?status=quoted')->assertOk()->assertJsonCount(0, 'data');
        $this->send('GET', self::QUEUE.'?status=new&account='.rawurlencode($buyer->email))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.0.customer_name', $buyer->name)->assertJsonPath('data.0.customer_email', $buyer->email);
        $this->send('GET', self::QUEUE.'?account='.rawurlencode($otherBuyer->email))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $other);
        $this->send('GET', self::MINE)->assertUnauthorized();
    }

    private function submit(string $title): string
    {
        return $this->send('POST', self::CREATE, ['title' => $title, 'quantity' => 1], (string) Str::uuid())
            ->assertCreated()->json('id');
    }

    private function login(bool $admin = false): User
    {
        $this->cookies = [];
        $user = User::factory()->create([
            'status' => 'active',
            'is_operator' => $admin,
            'password_hash' => password_hash('request-order-test', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        config(['auth.admin_2fa_enabled' => false]);
        $this->send('GET', '/sanctum/csrf-cookie'.($admin ? '?scope=admin&purpose=login' : ''))->assertNoContent();
        $this->send('POST', '/api/v1/'.($admin ? 'admin/' : '').'auth/login', [
            'email' => $user->email, 'password' => 'request-order-test',
        ])->assertOk();

        return $user;
    }

    private function send(string $method, string $path, array $data = [], ?string $key = null, bool $csrf = true): TestResponse
    {
        $headers = [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.42',
        ];
        if ($csrf && isset($this->cookies['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }
        if ($key !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        }
        $response = $this->call($method, $path, [], $this->cookies, [], $headers, $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        Auth::forgetGuards();

        return $response;
    }
}
