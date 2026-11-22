<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Application\Commands\ResolveFakeCheckout;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Platform\Domain\PublicId;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class PreorderCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private array $jar = [];

    private int $user;

    private int $variant;

    private int $batch;

    private int $slot;

    private string $batchCode;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'checkout.fake_payment_enabled' => true, 'session.lifetime' => 150000]);
        FeatureFlag::create(['code' => 'checkout.enabled', 'description' => 'Checkout', 'default_enabled' => true]);
        $this->user = User::factory()->create(['status' => 'active', 'password_hash' => password_hash('preorder-password', PASSWORD_BCRYPT, ['cost' => 4])])->id;
        $this->send('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->send('POST', '/api/v1/auth/login', ['email' => DB::table('users')->where('id', $this->user)->value('email'), 'password' => 'preorder-password'])->assertOk();
        $category = DB::table('categories')->insertGetId(['slug' => 'po-test', 'name_key' => 'PO']);
        $product = DB::table('products')->insertGetId(['slug' => 'po-test', 'name' => 'Nova', 'category_id' => $category, 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{po}']);
        $this->variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'PO-TEST', 'label' => 'Mint', 'base_price' => 100001]);
        $this->batchCode = 'batch_'.Str::ulid();
        $this->batch = DB::table('preorder_batches')->insertGetId(['public_id' => $this->batchCode, 'code' => 'PO-TEST', 'title' => 'Nova batch', 'status' => 'open', 'order_window_open_at' => now()->subDay(), 'order_window_close_at' => now()->addDays(14), 'deposit_value' => 5000]);
        $this->slot = DB::table('preorder_batch_items')->insertGetId(['public_id' => 'bitem_'.Str::ulid(), 'batch_id' => $this->batch, 'variant_id' => $this->variant, 'quota' => 5, 'max_per_account' => 2, 'price' => 90001]);
    }

    private function send(string $method, string $path, array $payload = [], ?string $key = null): TestResponse
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on', 'HTTP_IDEMPOTENCY_KEY' => $key ?? (string) Str::uuid()];
        if (isset($this->jar['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->jar['XSRF-TOKEN'];
        }
        $response = $this->call($method, $path, [], $this->jar, [], $headers, $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = $cookie->getValue();
            }
        }
        $this->app['auth']->forgetGuards();

        return $response;
    }

    private function checkoutPayload(int $quantity = 1): array
    {
        $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $this->variant), 'quantity' => $quantity])->assertCreated();
        $address = $this->send('POST', '/api/v1/me/addresses', ['recipient' => 'Rani', 'phone' => '081234567890', 'province' => 'DKI Jakarta', 'city' => 'Jakarta', 'district' => 'Gambir', 'postal_code' => '10110', 'address_line' => 'Jalan Merdeka 10'])->assertCreated()->json('id');
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');
        $session = $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $address, 'expected_items_hash' => $hash])->assertCreated()->assertJsonPath('shipping_estimate.amount_minor', 0)->json('id');
        $this->send('POST', '/api/v1/checkout/sessions/'.$session.'/shipping-options')->assertOk()->assertJsonCount(0, 'groups');

        return ['checkout_session_id' => $session, 'shipping_address_id' => $address];
    }

    public function test_public_reads_empty_unknown_and_detail(): void
    {
        $this->send('GET', '/api/v1/preorder-batches')->assertOk()->assertJsonPath('data.0.id', $this->batchCode);
        $this->send('GET', '/api/v1/preorder-batches/'.$this->batchCode)->assertOk()->assertJsonPath('items.0.remaining', 5)->assertJsonPath('items.0.max_per_account', 2)->assertJsonPath('items.0.product_slug', 'po-test')->assertJsonPath('items.0.price.amount_minor', 90001)->assertJsonStructure(['now', 'opens_at', 'closes_at', 'deposit_policy']);
        $this->send('GET', '/api/v1/preorder-batches/batch_'.Str::ulid())->assertNotFound();
        DB::table('preorder_batches')->where('id', $this->batch)->update(['status' => 'closed']);
        $this->send('GET', '/api/v1/preorder-batches')->assertOk()->assertJsonCount(0, 'data');
        $this->send('GET', '/api/v1/preorder-batches/'.$this->batchCode)->assertNotFound();
    }

    public function test_order_capture_and_idempotent_replay(): void
    {
        $payload = $this->checkoutPayload();
        $key = (string) Str::uuid();
        $response = $this->send('POST', '/api/v1/orders', $payload, $key)->assertCreated()->assertJsonPath('invoice.type', 'deposit')->assertJsonPath('total.amount_minor', 90001)->assertJsonPath('invoice.total.amount_minor', 45001);
        $this->send('POST', '/api/v1/orders', $payload, $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseCount('preorder_enrollments', 1);
        $this->assertDatabaseHas('orders', ['order_type' => 'preorder', 'fulfillment_lane' => 'import_po', 'fulfillment_status' => 'awaiting_batch', 'shipping_total' => 0]);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseHas('preorder_batch_items', ['id' => $this->slot, 'reserved_qty' => 1]);
        $order = $response->json();
        $path = '/api/v1/orders/'.$order['id'].'/fake-payment';
        $body = ['payment_intent_id' => $order['payment_intent']['id']];
        $this->send('POST', $path, $body)->assertOk()->assertJsonPath('status', 'paid');
        $this->send('POST', $path, $body)->assertOk();
        $this->assertDatabaseHas('preorder_enrollments', ['state' => 'deposit_paid', 'deposit_amount' => 45001]);
        $this->assertDatabaseHas('orders', ['payment_status' => 'partially_paid', 'order_status' => 'paid']);
        $this->assertDatabaseHas('invoices', ['type' => 'deposit', 'status' => 'paid', 'amount' => 45001]);
        $this->assertSame(1, DB::table('outbox_events')->where('event_name', 'DepositPaid')->count());
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonCount(0, 'groups');
    }

    #[TestWith(['future'])]
    #[TestWith(['past'])]
    #[TestWith(['closed'])]
    #[TestWith(['item_future'])]
    #[TestWith(['item_closed'])]
    public function test_batch_not_open(string $kind): void
    {
        $payload = $this->checkoutPayload();
        match ($kind) {
            'future' => DB::table('preorder_batches')->where('id', $this->batch)->update(['order_window_open_at' => now()->addDay()]),
            'past' => DB::table('preorder_batches')->where('id', $this->batch)->update(['order_window_close_at' => now()->subHour()]),
            'closed' => DB::table('preorder_batches')->where('id', $this->batch)->update(['status' => 'closed']),
            'item_future' => DB::table('preorder_batch_items')->where('id', $this->slot)->update(['opens_at' => now()->addDay()]),
            'item_closed' => DB::table('preorder_batch_items')->where('id', $this->slot)->update(['status' => 'closed']),
        };
        $this->send('POST', '/api/v1/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'BATCH_NOT_OPEN');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quota_exhausted_rolls_back_order(): void
    {
        $payload = $this->checkoutPayload();
        DB::table('preorder_batch_items')->where('id', $this->slot)->update(['reserved_qty' => 5]);
        $this->send('POST', '/api/v1/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'BATCH_QUOTA_EXHAUSTED');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('preorder_enrollments', 0);
    }

    public function test_account_limit_across_orders(): void
    {
        $payload = $this->checkoutPayload(2);
        $this->send('POST', '/api/v1/orders', $payload)->assertCreated();
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');
        $session = $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $payload['shipping_address_id'], 'expected_items_hash' => $hash])->assertCreated()->json('id');
        $payload['checkout_session_id'] = $session;
        $this->send('POST', '/api/v1/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'BATCH_ACCOUNT_LIMIT_EXCEEDED');
        $this->assertDatabaseHas('preorder_batch_items', ['id' => $this->slot, 'reserved_qty' => 2]);
    }

    public function test_expiry_releases_quota_once_and_prevents_capture(): void
    {
        $order = $this->send('POST', '/api/v1/orders', $this->checkoutPayload())->assertCreated()->json();
        $this->travel(121)->minutes();
        $this->assertSame(1, app(ResolveFakeCheckout::class)->expire(100));
        $this->assertSame(0, app(ResolveFakeCheckout::class)->expire(100));
        $this->assertDatabaseHas('preorder_batch_items', ['id' => $this->slot, 'reserved_qty' => 0]);
        $this->assertDatabaseHas('preorder_enrollments', ['state' => 'expired']);
        $this->assertDatabaseHas('orders', ['order_status' => 'expired']);
        $this->send('POST', '/api/v1/orders/'.$order['id'].'/fake-payment', ['payment_intent_id' => $order['payment_intent']['id']])->assertConflict()->assertJsonPath('code', 'PAYMENT_INTENT_EXPIRED');
    }

    public function test_mixed_cart_rejected(): void
    {
        $payload = $this->checkoutPayload();
        $product = DB::table('products')->insertGetId(['slug' => 'rts-mixed', 'name' => 'RTS', 'category_id' => DB::table('categories')->value('id'), 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{rts}', 'unit_weight_g' => 500]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'RTS-MIXED', 'label' => 'Standard', 'base_price' => 100]);
        $warehouse = DB::table('warehouses')->insertGetId(['code' => 'PO-TEST', 'name' => 'Warehouse', 'kind' => 'warehouse']);
        DB::table('stock_items')->insert(['warehouse_id' => $warehouse, 'variant_id' => $variant, 'on_hand' => 5]);
        $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $variant), 'quantity' => 1])->assertCreated();
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');
        $payload['checkout_session_id'] = $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $payload['shipping_address_id'], 'expected_items_hash' => $hash])->assertCreated()->json('id');
        $this->send('POST', '/api/v1/orders', $payload)->assertUnprocessable()->assertJsonPath('code', 'CHECKOUT_MIXED_MODES');
    }
}
