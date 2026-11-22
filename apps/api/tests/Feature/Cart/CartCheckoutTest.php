<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Cart\Application\CartService;
use App\Modules\Fulfillment\Contracts\InvalidDestination;
use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRate;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use App\Modules\Identity\Application\ActiveOrderAddressUsage;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Payments\Contracts\CheckoutPaymentIntents;
use App\Modules\Settings\Infrastructure\Models\FeatureFlag;
use App\Platform\Domain\PublicId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

final class CartCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private array $jar = [];

    private int $user;

    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'shipping.driver' => 'manual', 'session.lifetime' => 150000]);
        Cache::flush();
        $this->user = User::factory()->create(['status' => 'active', 'password_hash' => password_hash('cart-password', PASSWORD_BCRYPT, ['cost' => 4])])->id;
        $email = DB::table('users')->where('id', $this->user)->value('email');
        $this->send('GET', '/sanctum/csrf-cookie')->assertNoContent();
        $this->send('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'cart-password'])->assertOk();
        $cat = DB::table('categories')->insertGetId(['slug' => 'cart-test', 'name_key' => 'Cart']);
        $p = DB::table('products')->insertGetId(['slug' => 'cart-test', 'name' => 'Figure', 'category_id' => $cat, 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{rts}', 'unit_weight_g' => 500]);
        $this->variant = DB::table('product_variants')->insertGetId(['product_id' => $p, 'sku' => 'cart-test', 'label' => 'Standard', 'base_price' => 100000]);
        $w = DB::table('warehouses')->insertGetId(['code' => 'CART', 'name' => 'Cart', 'kind' => 'warehouse']);
        DB::table('stock_items')->insert(['warehouse_id' => $w, 'variant_id' => $this->variant, 'on_hand' => 5]);
    }

    private function send(string $method, string $path, array $payload = [], ?string $key = null, bool $csrf = true, bool $idempotency = true): TestResponse
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on'];
        if ($idempotency) {
            $headers['HTTP_IDEMPOTENCY_KEY'] = $key ?? (string) Str::uuid();
        }
        if ($csrf && isset($this->jar['XSRF-TOKEN'])) {
            $headers['HTTP_X_XSRF_TOKEN'] = $this->jar['XSRF-TOKEN'];
        }
        $r = $this->call($method, $path, [], $this->jar, [], $headers, $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR));
        foreach ($r->headers->getCookies() as $c) {
            if ($c->isCleared()) {
                unset($this->jar[$c->getName()]);
            } else {
                $this->jar[$c->getName()] = $c->getValue();
            }
        }
        $this->app['auth']->forgetGuards();

        return $r;
    }

    private function add(int $quantity = 1): TestResponse
    {
        return $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $this->variant), 'quantity' => $quantity]);
    }

    private function address(array $override = []): TestResponse
    {
        return $this->send('POST', '/api/v1/me/addresses', array_replace(['recipient' => 'Rani', 'phone' => '081234567890', 'province' => 'DKI Jakarta', 'city' => 'Jakarta', 'district' => 'Gambir', 'postal_code' => '10110', 'address_line' => 'Jalan Merdeka 10'], $override));
    }

    private function checkout(): TestResponse
    {
        $this->add()->assertCreated();
        $a = $this->address()->assertCreated()->json('id');
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');

        return $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $a, 'expected_items_hash' => $hash]);
    }

    public function test_place_order_reprices_replays_and_captures_once(): void
    {
        $this->enableFakePayment();
        $checkout = $this->checkout()->assertCreated()->assertJsonPath('fake_payment_available', true)->json();
        $payload = $this->quoteForOrder($checkout);
        DB::table('product_variants')->where('id', $this->variant)->update(['base_price' => 120000]);
        DB::table('shipping_tariff_rules')->update(['base_amount' => 13000]);

        $key = (string) Str::uuid();
        $this->send('POST', '/api/v1/orders', $payload, idempotency: false)->assertBadRequest()
            ->assertJsonPath('type', 'urn:elzara:problem:idempotency:key-required');
        $this->send('POST', '/api/v1/orders', $payload + ['total' => 1])->assertUnprocessable();
        $response = $this->send('POST', '/api/v1/orders', $payload, $key)->assertCreated()
            ->assertJsonPath('total.amount_minor', 133000)->assertJsonPath('repriced', true)
            ->assertJsonPath('changes_from_snapshot.0.from', 100000)->assertJsonPath('changes_from_snapshot.1.to', 13000)
            ->assertJsonPath('payment_intent.provider', 'fake');
        $order = $response->json();
        $expires = $order['reservation_expires_at'];
        $this->assertSame($expires, $order['invoice']['due_at']);
        $this->assertSame($expires, $order['payment_intent']['expires_at']);
        $replay = $this->send('POST', '/api/v1/orders', $payload, $key)
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($response->getContent(), $replay->getContent());
        $this->assertSame($response->headers->get('X-Request-Id'), $replay->headers->get('X-Request-Id'));
        $this->send('POST', '/api/v1/orders', $payload + ['note' => 'different'], $key)->assertConflict()
            ->assertJsonPath('type', 'urn:elzara:problem:idempotency:key-payload-mismatch');
        $this->send('POST', '/api/v1/orders', $payload)->assertConflict()->assertJsonPath('code', 'CHECKOUT_SESSION_CONVERTED');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payment_intents', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseHas('stock_items', ['variant_id' => $this->variant, 'reserved' => 1, 'available_to_sell' => 4]);
        $placed = DB::table('outbox_events')->where('event_name', 'OrderPlaced')->get()->sole();
        $orderId = DB::table('orders')->where('code', $order['id'])->value('id');
        $this->assertSame($orderId, $placed->aggregate_id);
        $this->assertSame($response->headers->get('X-Request-Id'), $placed->request_id);
        $this->assertNull($placed->dispatched_at);
        $eventPayload = json_decode($placed->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals([
            'order_id' => $orderId, 'order_type' => 'ready_stock', 'customer_id' => $this->user,
            'grand_total' => 133000, 'currency' => 'IDR', 'item_count' => 1, 'expires_at' => $expires,
        ], $eventPayload);

        $path = '/api/v1/orders/'.$order['id'].'/fake-payment';
        $body = ['payment_intent_id' => $order['payment_intent']['id']];
        $this->send('POST', $path, $body, csrf: false)->assertStatus(419);
        $this->send('POST', $path, ['payment_intent_id' => 'pi_'.Str::ulid()])->assertNotFound();
        $paymentKey = (string) Str::uuid();
        $capture = $this->send('POST', $path, $body, $paymentKey)->assertOk()->assertJsonPath('status', 'paid');
        $this->send('POST', $path, $body, $paymentKey)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->send('POST', $path, $body)->assertOk()->assertJsonPath('status', 'paid');
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonCount(0, 'groups');
        $this->travel(3)->minutes();
        $this->artisan('inventory:expire-checkout-reservations')->expectsOutput('expired=0')->assertSuccessful();
        $this->assertDatabaseHas('orders', ['code' => $order['id'], 'order_status' => 'paid', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('invoices', ['code' => $order['invoice']['id'], 'status' => 'paid']);
        $this->assertDatabaseHas('payment_intents', ['code' => $order['payment_intent']['id'], 'status' => 'captured']);
        $this->assertDatabaseHas('stock_reservations', ['variant_id' => $this->variant, 'status' => 'committed']);
        $this->assertDatabaseHas('stock_items', ['variant_id' => $this->variant, 'reserved' => 1, 'on_hand' => 5]);
        $audit = $this->orderAudit($order['id']);
        $this->assertSame([
            [['fulfillment_status' => 'unprocessed'], ['fulfillment_status' => 'reserved'], 'Stok checkout direservasi.'],
            [['order_status' => 'awaiting_payment'], ['order_status' => 'paid'], 'Pembayaran simulasi berhasil.'],
            [['payment_status' => 'unpaid'], ['payment_status' => 'paid'], 'Pembayaran simulasi berhasil.'],
        ], $audit->map(fn (object $row): array => [json_decode($row->before, true), json_decode($row->after, true), $row->reason])->all());
        $this->assertSame([$this->user, $this->user, $this->user], $audit->pluck('actor_id')->all());
        $this->assertSame([$response->headers->get('X-Request-Id'), $capture->headers->get('X-Request-Id'), $capture->headers->get('X-Request-Id')], $audit->pluck('request_id')->all());
    }

    public function test_payment_only_consumes_purchased_quantity_once(): void
    {
        $this->enableFakePayment();
        $payload = $this->quoteForOrder($this->checkout()->assertCreated()->json());
        $order = $this->send('POST', '/api/v1/orders', $payload)->assertCreated()->json();
        $this->add(2)->assertCreated();

        $path = '/api/v1/orders/'.$order['id'].'/fake-payment';
        $body = ['payment_intent_id' => $order['payment_intent']['id']];
        $this->send('POST', $path, $body)->assertOk();
        $this->send('POST', $path, $body)->assertOk();
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('groups.0.items.0.quantity', 2);
    }

    public function test_expiry_releases_inventory_and_leaves_the_cart_retryable(): void
    {
        $this->enableFakePayment();
        $payload = $this->quoteForOrder($this->checkout()->assertCreated()->json());
        $order = $this->send('POST', '/api/v1/orders', $payload)->assertCreated()->json();
        $this->travel(3)->minutes();
        Cache::flush();
        $this->send('POST', '/api/v1/orders/'.$order['id'].'/fake-payment', ['payment_intent_id' => $order['payment_intent']['id']])
            ->assertConflict()->assertJsonPath('code', 'PAYMENT_INTENT_EXPIRED');
        $this->artisan('inventory:expire-checkout-reservations')->expectsOutput('expired=1')->assertSuccessful();
        $this->artisan('inventory:expire-checkout-reservations')->expectsOutput('expired=0')->assertSuccessful();
        $this->assertDatabaseHas('stock_items', ['variant_id' => $this->variant, 'reserved' => 0, 'available_to_sell' => 5]);
        $this->assertDatabaseHas('stock_reservations', ['variant_id' => $this->variant, 'status' => 'expired']);
        $this->assertDatabaseHas('orders', ['code' => $order['id'], 'order_status' => 'expired', 'fulfillment_status' => 'unprocessed']);
        $audit = $this->orderAudit($order['id'])->slice(1)->values();
        $this->assertSame([
            [['order_status' => 'awaiting_payment'], ['order_status' => 'expired']],
            [['fulfillment_status' => 'reserved'], ['fulfillment_status' => 'unprocessed']],
        ], $audit->map(fn (object $row): array => [json_decode($row->before, true), json_decode($row->after, true)])->all());
        $this->assertSame([null, null], $audit->pluck('actor_id')->all());
        $this->assertSame(['Reservasi checkout kedaluwarsa.'], $audit->pluck('reason')->unique()->all());
        $this->assertDatabaseHas('invoices', ['code' => $order['invoice']['id'], 'status' => 'void']);
        $this->assertDatabaseHas('payment_intents', ['code' => $order['payment_intent']['id'], 'status' => 'expired']);
        $this->assertSame(1, DB::table('outbox_events')->where('event_name', 'ReservationExpired')->count());
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('groups.0.items.0.quantity', 1);
    }

    public function test_insufficient_stock_rolls_back_the_whole_checkout(): void
    {
        $this->enableFakePayment();
        $product = DB::table('product_variants')->where('id', $this->variant)->value('product_id');
        $other = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'cart-other', 'label' => 'Other', 'base_price' => 50000]);
        $warehouse = DB::table('warehouses')->where('code', 'CART')->value('id');
        DB::table('stock_items')->insert(['warehouse_id' => $warehouse, 'variant_id' => $other, 'on_hand' => 1]);
        $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $other), 'quantity' => 1])->assertCreated();
        $payload = $this->quoteForOrder($this->checkout()->assertCreated()->json());
        DB::table('stock_items')->where('variant_id', $other)->update(['quarantined' => 1]);

        $key = (string) Str::uuid();
        $this->send('POST', '/api/v1/orders', $payload, $key)->assertUnprocessable()->assertJsonPath('code', 'INSUFFICIENT_STOCK');
        $this->send('POST', '/api/v1/orders', $payload, $key)->assertUnprocessable()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertDatabaseHas('checkout_sessions', ['code' => $payload['checkout_session_id'], 'status' => 'open']);
        $this->assertDatabaseHas('stock_items', ['variant_id' => $this->variant, 'reserved' => 0]);
        foreach (['orders', 'order_items', 'stock_reservations', 'stock_movements', 'invoices', 'payment_intents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, DB::table('outbox_events')->where('event_name', 'StockReserved')->count());
        $this->assertSame(0, DB::table('outbox_events')->where('event_name', 'OrderPlaced')->count());
    }

    public function test_failure_after_reserving_rolls_back_order_and_transition_audit(): void
    {
        $this->enableFakePayment();
        $payload = $this->quoteForOrder($this->checkout()->assertCreated()->json());
        $this->mock(CheckoutPaymentIntents::class)
            ->shouldReceive('issue')->once()->andReturnUsing(function (): never {
                $this->assertDatabaseHas('orders', ['fulfillment_status' => 'reserved']);
                $this->assertDatabaseHas('audit_logs', ['action' => 'order.state.transition', 'reason' => 'Stok checkout direservasi.']);

                throw new \RuntimeException('Fake payment fixture failed after reservation.');
            });

        $this->send('POST', '/api/v1/orders', $payload)->assertServerError();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'order.state.transition']);
    }

    private function orderAudit(string $orderCode): Collection
    {
        return DB::table('audit_logs')
            ->where('action', 'order.state.transition')
            ->where('target_type', 'order')
            ->where('target_id', DB::table('orders')->where('code', $orderCode)->select('id'))
            ->orderBy('id')->get();
    }

    public function test_order_audit_preserves_the_full_client_request_id(): void
    {
        $this->enableFakePayment();
        $payload = $this->quoteForOrder($this->checkout()->assertCreated()->json());
        $requestId = str_repeat('checkout-request', 8);
        $this->withServerVariables(['HTTP_X_REQUEST_ID' => $requestId]);

        $order = $this->send('POST', '/api/v1/orders', $payload)->assertCreated()
            ->assertHeader('X-Request-Id', $requestId)->json();

        $audit = $this->orderAudit($order['id'])->sole();
        $this->assertTrue(Str::isUlid($audit->request_id));
        $this->assertSame($requestId, json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR)['_context']['request_id']);
    }

    #[TestWith(['staging'])]
    #[TestWith(['production'])]
    public function test_fake_payment_cannot_be_enabled_in_deployed_environments(string $environment): void
    {
        $this->enableFakePayment();
        config(['surfaces.admin_origin' => 'https://admin.localhost']);
        $this->app->detectEnvironment(fn () => $environment);
        $checkout = $this->checkout()->assertCreated()->assertJsonPath('fake_payment_available', false)->json();
        $payload = $this->quoteForOrder($checkout);
        $this->send('POST', '/api/v1/orders', $payload)->assertConflict()->assertJsonPath('code', 'FAKE_PAYMENT_DISABLED');
        $this->send('POST', '/api/v1/orders/ord_'.Str::ulid().'/fake-payment', ['payment_intent_id' => 'pi_'.Str::ulid()])
            ->assertConflict()->assertJsonPath('code', 'FAKE_PAYMENT_DISABLED');
        $this->assertDatabaseCount('orders', 0);
    }

    private function enableFakePayment(): void
    {
        config(['checkout.fake_payment_enabled' => true, 'checkout.warehouse_code' => 'CART', 'checkout.reservation_ttl_minutes' => 2]);
        FeatureFlag::create(['code' => 'checkout.enabled', 'description' => 'Checkout', 'default_enabled' => true]);
    }

    private function quoteForOrder(array $checkout): array
    {
        DB::table('courier_services')->insert(['courier_code' => 'jne', 'service_code' => 'REG', 'name' => 'Regular']);
        DB::table('shipping_tariff_rules')->insert(['courier_code' => 'jne', 'service_code' => 'REG', 'package_tier' => 'paket_besar', 'weight_from_g' => 0, 'base_amount' => 10000, 'effective_from' => now()->toDateString(), 'created_by' => $this->user]);
        $this->send('POST', '/api/v1/checkout/sessions/'.$checkout['id'].'/shipping-options')->assertOk();

        return ['checkout_session_id' => $checkout['id'], 'shipping_address_id' => $checkout['snapshot']['address']['id']];
    }

    public function test_add_merge_update_remove_stock_and_repricing(): void
    {
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('groups', [])->assertHeader('Cache-Control', 'no-store, private');
        $first = $this->add(2)->assertCreated()->assertJsonPath('subtotal.amount_minor', 200000);
        $id = $first->json('groups.0.items.0.id');
        $this->add()->assertCreated()->assertJsonCount(1, 'groups.0.items')->assertJsonPath('groups.0.items.0.quantity', 3);
        $this->add(3)->assertStatus(422)->assertJsonPath('code', 'CART_ITEM_UNAVAILABLE');
        $this->send('PATCH', '/api/v1/cart/items/'.$id, ['quantity' => 1])->assertOk()->assertJsonPath('subtotal.amount_minor', 100000);
        DB::table('product_variants')->where('id', $this->variant)->update(['base_price' => 120000]);
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('groups.0.items.0.price_changed', true)->assertJsonPath('subtotal.amount_minor', 120000);
        DB::table('stock_items')->where('variant_id', $this->variant)->update(['on_hand' => 0]);
        $this->send('GET', '/api/v1/cart')->assertJsonPath('groups.0.items.0.unavailable', true);
        $this->send('DELETE', '/api/v1/cart/items/'.$id)->assertOk()->assertJsonPath('groups', []);
    }

    public function test_auth_csrf_validation_and_idempotency_replay(): void
    {
        $payload = ['variant_id' => PublicId::encode('var', $this->variant), 'quantity' => 1];
        $this->send('POST', '/api/v1/cart/items', $payload, csrf: false)->assertStatus(419);
        $key = (string) Str::uuid();
        $a = $this->send('POST', '/api/v1/cart/items', $payload, $key)->assertCreated();
        $b = $this->send('POST', '/api/v1/cart/items', $payload, $key)->assertCreated();
        $this->assertSame($a->json(), $b->json());
        $this->assertDatabaseCount('cart_items', 1);
        $this->send('POST', '/api/v1/cart/items', array_replace($payload, ['quantity' => 0]))->assertStatus(422);
        DB::table('product_variants')->where('id', $this->variant)->update(['currency_code' => 'USD']);
        $this->add()->assertStatus(422);
        DB::table('product_variants')->where('id', $this->variant)->update(['currency_code' => 'IDR', 'is_active' => false]);
        $this->add()->assertStatus(422);
        $this->jar = [];
        $this->send('GET', '/api/v1/cart')->assertUnauthorized();
    }

    public function test_lines_identify_two_variants_of_the_same_product(): void
    {
        $this->add()->assertCreated()->assertJsonPath('groups.0.items.0.variant_label', 'Standard')->assertJsonPath('groups.0.items.0.sku', 'cart-test');
        $product = DB::table('product_variants')->where('id', $this->variant)->value('product_id');
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'cart-blue', 'label' => 'Blue / Large', 'base_price' => 100000]);
        DB::table('stock_items')->insert(['warehouse_id' => DB::table('warehouses')->where('code', 'CART')->value('id'), 'variant_id' => $variant, 'on_hand' => 5]);
        $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $variant), 'quantity' => 1])->assertCreated()->assertJsonCount(2, 'groups.0.items')
            ->assertJsonPath('groups.0.items.1.name', 'Figure')->assertJsonPath('groups.0.items.1.variant_label', 'Blue / Large')->assertJsonPath('groups.0.items.1.sku', 'cart-blue');
        DB::table('product_variants')->where('id', $variant)->update(['is_active' => false]);
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('groups.0.items.1.variant_label', null)->assertJsonPath('groups.0.items.1.unavailable', true);
    }

    public function test_products_without_modes_default_to_ready_stock(): void
    {
        DB::table('products')->where('slug', 'cart-test')->update(['fulfillment_modes' => '{}']);
        $this->add()->assertCreated()->assertJsonPath('groups.0.mode', 'rts');
        $this->add(5)->assertStatus(422)->assertJsonPath('code', 'CART_ITEM_UNAVAILABLE');
    }

    public function test_fulfillment_changes_invalidate_the_checkout_hash(): void
    {
        $this->add()->assertCreated();
        $address = $this->address()->assertCreated()->json('id');
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');
        $this->assertSame($hash, $this->send('GET', '/api/v1/cart')->json('items_hash'));
        DB::table('products')->where('slug', 'cart-test')->update(['fulfillment_modes' => '{po}']);
        $this->assertNotSame($hash, $this->send('GET', '/api/v1/cart')->json('items_hash'));
        $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $address, 'expected_items_hash' => $hash])->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_SNAPSHOT_STALE');
    }

    public function test_sliding_expiry_read_empty_new_add_and_command(): void
    {
        $a = $this->add()->assertCreated()->json('id');
        $this->travel(29)->days();
        Cache::flush();
        $line = $this->send('GET', '/api/v1/cart')->assertOk()->json('groups.0.items.0.id');
        $this->send('PATCH', '/api/v1/cart/items/'.$line, ['quantity' => 2])->assertOk();
        $this->travel(2)->days();
        Cache::flush();
        $this->assertSame($a, $this->send('GET', '/api/v1/cart')->assertOk()->json('id'));
        $this->travel(31)->days();
        Cache::flush();
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('groups', []);
        $this->send('PATCH', '/api/v1/cart/items/'.$line, ['quantity' => 1])->assertNotFound();
        $new = $this->add()->assertCreated()->json('id');
        $this->assertNotSame($a, $new);
        $this->travel(31)->days();
        Cache::flush();
        $this->artisan('cart:expire')->expectsOutput('expired=1')->assertSuccessful();
        $this->travelBack();
    }

    public function test_mutations_lock_the_cart_and_expiry_preserves_the_refreshed_ttl(): void
    {
        $first = $this->add()->assertCreated();
        $id = PublicId::decode($first->json('id'), 'cart');
        DB::table('carts')->where('id', $id)->update(['expires_at' => now()->addSecond()]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $line = $first->json('groups.0.items.0.id');
        $this->send('PATCH', '/api/v1/cart/items/'.$line, ['quantity' => 2])->assertOk();
        $this->assertTrue(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "carts"') && str_ends_with($sql, 'for update')));
        $this->travel(2)->seconds();
        $this->assertSame(0, app(CartService::class)->expire());
        $this->assertDatabaseHas('carts', ['id' => $id, 'status' => 'active']);
        $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonPath('id', $first->json('id'))->assertJsonPath('groups.0.items.0.quantity', 2);
        $this->travelBack();
    }

    public function test_groups_modes_and_stable_hash(): void
    {
        $this->add()->assertCreated();
        foreach (['po' => 'pre_order', 'request' => 'request_order'] as $mode => $expected) {
            $p = DB::table('products')->insertGetId(['slug' => $mode, 'name' => $mode, 'category_id' => DB::table('categories')->where('slug', 'cart-test')->value('id'), 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{'.$mode.'}', 'unit_weight_g' => 100]);
            $v = DB::table('product_variants')->insertGetId(['product_id' => $p, 'sku' => $mode, 'label' => $mode, 'base_price' => 10]);
            $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $v), 'quantity' => 1])->assertCreated();
        }
        $a = $this->send('GET', '/api/v1/cart')->assertOk()->assertJsonCount(3, 'groups')->assertJsonPath('groups.1.mode', 'pre_order')->assertJsonPath('groups.2.mode', 'request_order');
        $this->assertSame($a->json('items_hash'), $this->send('GET', '/api/v1/cart')->json('items_hash'));
        DB::table('product_variants')->where('id', $this->variant)->update(['base_price' => 100001]);
        $this->assertNotSame($a->json('items_hash'), $this->send('GET', '/api/v1/cart')->json('items_hash'));
    }

    public function test_address_validation_normalization_default_and_archive(): void
    {
        $this->address(['postal_code' => 10110])->assertStatus(422)->assertJsonPath('code', 'ADDRESS_INVALID');
        $this->address(['phone' => '123', 'postal_code' => '12'])->assertStatus(422)->assertJsonPath('code', 'ADDRESS_INVALID')->assertJsonFragment(['pointer' => '/phone'])->assertJsonFragment(['pointer' => '/postal_code']);
        $a = $this->address()->assertCreated()->assertJsonPath('phone', '+6281234567890')->assertJsonPath('is_default', true)->json('id');
        $b = $this->address(['phone' => '6281234567890'])->assertCreated()->assertJsonPath('is_default', false)->json('id');
        $this->send('PATCH', '/api/v1/me/addresses/'.$b, ['recipient' => 'Ayu'])->assertOk()->assertJsonPath('recipient', 'Ayu');
        $this->send('POST', '/api/v1/me/addresses/'.$b.'/set-default')->assertOk()->assertJsonPath('is_default', true);
        $this->send('POST', '/api/v1/me/addresses/'.$b.'/set-default')->assertOk()->assertJsonPath('is_default', true);
        $this->send('GET', '/api/v1/me/addresses/'.$b)->assertOk()->assertJsonPath('is_default', true);
        $this->assertDatabaseCount('addresses', 2);
        $this->assertSame(1, DB::table('addresses')->where('user_id', $this->user)->where('is_default', true)->count());
        $this->send('DELETE', '/api/v1/me/addresses/'.$b)->assertNoContent();
        $this->send('GET', '/api/v1/me/addresses/'.$b)->assertNotFound();
        $this->send('GET', '/api/v1/me/addresses')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a)->assertJsonPath('data.0.is_default', true);
        $this->assertDatabaseHas('addresses', ['id' => PublicId::decode($b, 'addr'), 'is_archived' => true]);
        $this->app->bind(ActiveOrderAddressUsage::class, fn () => new class implements ActiveOrderAddressUsage
        {
            public function isUsedByActiveOrder(int $addressId): bool
            {
                return true;
            }
        });
        $this->send('DELETE', '/api/v1/me/addresses/'.$a)->assertStatus(409);
    }

    public function test_another_users_resources_are_hidden(): void
    {
        $s = $this->checkout()->assertCreated()->json('id');
        $line = $this->send('GET', '/api/v1/cart')->json('groups.0.items.0.id');
        $addr = $this->send('GET', '/api/v1/me/addresses')->json('data.0.id');
        $other = User::factory()->create();
        DB::table('carts')->where('user_id', $this->user)->update(['user_id' => $other->id]);
        DB::table('checkout_sessions')->where('user_id', $this->user)->update(['user_id' => $other->id]);
        DB::table('addresses')->where('user_id', $this->user)->update(['user_id' => $other->id]);
        $this->send('PATCH', '/api/v1/cart/items/'.$line, ['quantity' => 1])->assertNotFound();
        $this->send('GET', '/api/v1/checkout/sessions/'.$s)->assertNotFound();
        $this->send('POST', '/api/v1/checkout/sessions/'.$s.'/shipping-options')->assertNotFound();
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $this->send($method, '/api/v1/me/addresses/'.$addr, $method === 'PATCH' ? ['recipient' => 'X'] : [])->assertNotFound();
        }
        $this->send('POST', '/api/v1/me/addresses/'.$addr.'/set-default')->assertNotFound();
    }

    public function test_checkout_empty_stale_unavailable_and_expiry(): void
    {
        $a = $this->address()->assertCreated()->json('id');
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');
        $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $a, 'expected_items_hash' => $hash])->assertStatus(422)->assertJsonPath('code', 'CART_EMPTY');
        $this->add()->assertCreated();
        $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $a, 'expected_items_hash' => $hash])->assertStatus(409)->assertJsonPath('code', 'CHECKOUT_SNAPSHOT_STALE');
        $hash = $this->send('GET', '/api/v1/cart')->json('items_hash');
        DB::table('stock_items')->where('variant_id', $this->variant)->update(['on_hand' => 0]);
        $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $a, 'expected_items_hash' => $hash])->assertStatus(422)->assertJsonPath('code', 'CART_ITEM_UNAVAILABLE');
        DB::table('stock_items')->where('variant_id', $this->variant)->update(['on_hand' => 5]);
        $id = $this->send('POST', '/api/v1/checkout/sessions', ['shipping_address_id' => $a, 'expected_items_hash' => $hash])->assertCreated()->json('id');
        $this->travel(121)->minutes();
        Cache::flush();
        $this->send('GET', '/api/v1/checkout/sessions/'.$id)->assertOk()->assertJsonPath('status', 'expired');
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertStatus(422)->assertJsonPath('code', 'CHECKOUT_SESSION_EXPIRED');
        $this->travelBack();
    }

    public function test_manual_shipping_and_failure_mapping(): void
    {
        $id = $this->checkout()->assertCreated()->json('id');
        DB::table('courier_services')->insert(['courier_code' => 'jne', 'service_code' => 'REG', 'name' => 'Regular']);
        DB::table('shipping_tariff_rules')->insert(['courier_code' => 'jne', 'service_code' => 'REG', 'package_tier' => 'paket_besar', 'weight_from_g' => 0, 'base_amount' => 10000, 'effective_from' => now()->toDateString(), 'created_by' => $this->user]);
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertOk()->assertJsonPath('groups.0.options.0.amountMinor', 10000)->assertJsonPath('checkout.shipping_estimate.amount_minor', 10000)->assertJsonPath('checkout.total.amount_minor', 110000);
        $this->app->bind(ShippingRateProvider::class, fn () => new class implements ShippingRateProvider
        {
            public function rates(ShippingRateRequest $request): array
            {
                throw new ProviderUnavailable('down');
            }
        });
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertStatus(503)->assertJsonPath('code', 'SHIPPING_PROVIDER_UNAVAILABLE');
        $this->app->bind(ShippingRateProvider::class, fn () => new class implements ShippingRateProvider
        {
            public function rates(ShippingRateRequest $request): array
            {
                throw new InvalidDestination('bad');
            }
        });
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertStatus(422)->assertJsonPath('code', 'ADDRESS_INVALID')->assertJsonFragment(['pointer' => '/shipping_address_id']);
    }

    public function test_shipping_selection_uses_all_stored_quotes_and_replays_idempotently(): void
    {
        $product = DB::table('products')->insertGetId(['slug' => 'shipping-po', 'name' => 'PO', 'category_id' => DB::table('categories')->where('slug', 'cart-test')->value('id'), 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{po}', 'unit_weight_g' => 100]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'shipping-po', 'label' => 'PO', 'base_price' => 10]);
        $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $variant), 'quantity' => 1])->assertCreated();
        $session = $this->checkout()->assertCreated();
        $id = $session->json('id');
        $subtotal = $session->json('subtotal.amount_minor');
        $provider = new class implements ShippingRateProvider
        {
            public int $calls = 0;

            public function rates(ShippingRateRequest $request): array
            {
                $this->calls++;

                return [new ShippingRate('jne', 'REG', 'Regular', 9000, 'IDR', null, null, 'fake'), new ShippingRate('jne', 'YES', 'Express', 24000, 'IDR', 1, 2, 'fake')];
            }
        };
        $this->app->instance(ShippingRateProvider::class, $provider);
        $url = '/api/v1/checkout/sessions/'.$id;
        $this->send('POST', $url.'/shipping-options')->assertOk()->assertJsonPath('checkout.shipping_estimate.amount_minor', 18000);
        $choices = [['group_index' => 0, 'courier_code' => 'jne', 'service_code' => 'YES'], ['group_index' => 1, 'courier_code' => 'jne', 'service_code' => 'REG']];
        $key = (string) Str::uuid();
        $response = $this->send('PUT', $url.'/shipping-selection', ['selections' => $choices], $key)->assertOk()
            ->assertJsonPath('shipping_estimate.amount_minor', 33000)->assertJsonPath('total.amount_minor', $subtotal + 33000)
            ->assertJsonPath('snapshot.shipping_estimate', 33000)->assertJsonPath('snapshot.total', $subtotal + 33000)
            ->assertJsonPath('snapshot.shipping_selection.0.service_code', 'YES');
        $this->assertSame($response->json(), $this->send('PUT', $url.'/shipping-selection', ['selections' => $choices], $key)->assertOk()->json());
        $this->send('GET', $url)->assertOk()->assertJsonPath('shipping_estimate.amount_minor', 33000);
        $this->assertSame(2, $provider->calls);
        foreach ([
            [[], 422],
            [[$choices[0]], 422],
            [[$choices[0], $choices[0]], 409],
            [[array_replace($choices[0], ['service_code' => 'FAKE']), $choices[1]], 409],
            [[array_replace($choices[0], ['group_index' => 99]), $choices[1]], 409],
            [[array_replace($choices[0], ['amountMinor' => 1]), $choices[1]], 422],
        ] as [$invalid, $status]) {
            $this->send('PUT', $url.'/shipping-selection', ['selections' => $invalid])->assertStatus($status);
        }
        $this->send('GET', $url)->assertOk()->assertJsonPath('total.amount_minor', $subtotal + 33000);
        $this->send('POST', $url.'/shipping-options')->assertOk()->assertJsonPath('checkout.shipping_estimate.amount_minor', 18000)->assertJsonPath('checkout.snapshot.shipping_selection.0.service_code', 'REG');
    }

    public function test_shipping_selection_rejects_missing_quotes_expiry_locked_and_foreign_sessions(): void
    {
        $id = $this->checkout()->assertCreated()->json('id');
        $url = '/api/v1/checkout/sessions/'.$id.'/shipping-selection';
        $payload = ['selections' => [['group_index' => 0, 'courier_code' => 'jne', 'service_code' => 'REG']]];
        $this->send('PUT', $url, $payload)->assertStatus(409)->assertJsonPath('code', 'SHIPPING_QUOTES_REQUIRED');
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertOk();
        $this->send('PUT', $url, $payload)->assertStatus(409)->assertJsonPath('code', 'SHIPPING_SELECTION_INVALID');
        DB::table('checkout_sessions')->where('code', $id)->update(['status' => 'locked']);
        $this->send('PUT', $url, $payload)->assertStatus(422)->assertJsonPath('code', 'CHECKOUT_SESSION_EXPIRED');
        DB::table('checkout_sessions')->where('code', $id)->update(['status' => 'open']);
        $this->travel(121)->minutes();
        Cache::flush();
        $this->send('PUT', $url, $payload)->assertStatus(422)->assertJsonPath('code', 'CHECKOUT_SESSION_EXPIRED');
        $this->travelBack();
        Cache::flush();
        $other = User::factory()->create();
        DB::table('checkout_sessions')->where('code', $id)->update(['user_id' => $other->id]);
        $this->send('PUT', $url, $payload)->assertNotFound();
    }

    public function test_empty_rates_and_missing_weight(): void
    {
        $id = $this->checkout()->assertCreated()->json('id');
        $this->send('GET', '/api/v1/checkout/sessions/'.$id)->assertOk()->assertJsonPath('shipping_estimate', null)->assertJsonPath('total', null);
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertOk()->assertJsonPath('groups.0.options', [])->assertJsonPath('checkout.shipping_estimate', null)->assertJsonPath('checkout.total', null)->assertJsonPath('checkout.snapshot.total', null);
        DB::table('products')->where('slug', 'cart-test')->update(['unit_weight_g' => null]);
        $id = $this->checkout()->assertCreated()->json('id');
        $this->send('POST', '/api/v1/checkout/sessions/'.$id.'/shipping-options')->assertStatus(422)->assertJsonPath('code', 'SHIPPING_WEIGHT_MISSING');
    }

    public function test_a_group_without_rates_makes_the_entire_estimate_incomplete(): void
    {
        $product = DB::table('products')->insertGetId(['slug' => 'unshippable-po', 'name' => 'PO', 'category_id' => DB::table('categories')->where('slug', 'cart-test')->value('id'), 'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{po}', 'unit_weight_g' => 100]);
        $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'unshippable-po', 'label' => 'PO', 'base_price' => 10]);
        $this->send('POST', '/api/v1/cart/items', ['variant_id' => PublicId::encode('var', $variant), 'quantity' => 1])->assertCreated();
        $id = $this->checkout()->assertCreated()->json('id');
        $this->app->bind(ShippingRateProvider::class, fn () => new class implements ShippingRateProvider
        {
            public function rates(ShippingRateRequest $request): array
            {
                return $request->weightGrams === 100 ? [] : [new ShippingRate('jne', 'REG', 'Regular', 9000, 'IDR', null, null, 'fake')];
            }
        });
        $url = '/api/v1/checkout/sessions/'.$id;
        $this->send('POST', $url.'/shipping-options')->assertOk()->assertJsonCount(2, 'groups')->assertJsonCount(1, 'checkout.snapshot.shipping_selection')
            ->assertJsonPath('checkout.shipping_estimate', null)->assertJsonPath('checkout.total', null)->assertJsonPath('checkout.snapshot.shipping_estimate', null)->assertJsonPath('checkout.snapshot.total', null);
        $this->send('GET', $url)->assertOk()->assertJsonPath('shipping_estimate', null)->assertJsonPath('total', null);
    }
}
