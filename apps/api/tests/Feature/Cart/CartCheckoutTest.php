<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Modules\Fulfillment\Contracts\InvalidDestination;
use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRate;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use App\Modules\Identity\Application\ActiveOrderAddressUsage;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Domain\PublicId;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    private function send(string $method, string $path, array $payload = [], ?string $key = null, bool $csrf = true): TestResponse
    {
        foreach (Route::getRoutes() as $route) {
            $route->flushController();
        }
        $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost', 'HTTP_ORIGIN' => 'https://localhost', 'HTTPS' => 'on', 'HTTP_IDEMPOTENCY_KEY' => $key ?? (string) Str::uuid()];
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
