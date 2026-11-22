<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

use App\Modules\Cart\Contracts\ReadyStockCheckout;
use App\Modules\Cart\Contracts\ReadyStockQuote;
use App\Modules\Cart\Infrastructure\Models\CartItem;
use App\Modules\Cart\Infrastructure\Models\CartRecord;
use App\Modules\Cart\Infrastructure\Models\CheckoutSession;
use App\Modules\Catalog\Contracts\SellableVariants;
use App\Modules\Fulfillment\Contracts\InvalidDestination;
use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use App\Modules\Identity\Contracts\CustomerAddresses;
use App\Modules\Payments\Contracts\FakePaymentAvailability;
use App\Platform\Domain\PublicId;
use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutService implements ReadyStockCheckout
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CustomerAddresses $addresses,
        private readonly ShippingRateProvider $shipping,
        private readonly SellableVariants $variants,
    ) {}

    public function prepare(int $user, string $sessionCode, string $addressCode): ReadyStockQuote
    {
        DB::table('users')->where('id', $user)->lockForUpdate()->firstOrFail();
        $session = CheckoutSession::query()->where('user_id', $user)->where('code', $sessionCode)->lockForUpdate()->firstOrFail();
        if ($session->status === 'converted') {
            $this->problem(409, 'CHECKOUT_SESSION_CONVERTED');
        }
        if ($session->expires_at->isPast() || $session->status !== 'open') {
            $this->problem(422, 'CHECKOUT_SESSION_EXPIRED');
        }
        $snapshot = $session->snapshot;
        $oldAddress = $snapshot['address'] ?? null;
        if (! is_array($oldAddress) || ($oldAddress['id'] ?? null) !== $addressCode) {
            $this->problem(422, 'ADDRESS_INVALID');
        }
        $address = $this->addresses->owned($user, $addressCode);
        $oldCart = $snapshot['cart'] ?? null;
        $groups = is_array($oldCart) ? ($oldCart['groups'] ?? null) : null;
        $group = is_array($groups) && count($groups) === 1 ? ($groups[0] ?? null) : null;
        if (! is_array($group) || ($group['mode'] ?? null) !== 'rts' || ! is_array($group['items'] ?? null)) {
            $this->problem(422, 'CHECKOUT_RTS_ONLY');
        }
        $selections = $snapshot['shipping_selection'] ?? null;
        $selection = is_array($selections) && is_array($selections[0] ?? null) ? $selections[0] : [];
        $courier = $selection['courier_code'] ?? null;
        $service = $selection['service_code'] ?? null;
        if (! is_string($courier) || ! is_string($service) || ! is_int($snapshot['shipping_estimate'] ?? null)) {
            $this->problem(409, 'SHIPPING_SELECTION_REQUIRED');
        }
        $cart = CartRecord::query()->whereKey($session->cart_id)->where('user_id', $user)->where('status', 'active')
            ->where('expires_at', '>', now())->lockForUpdate()->first();
        if ($cart === null) {
            $this->problem(409, 'CHECKOUT_SNAPSHOT_STALE');
        }
        $items = CartItem::query()->where('cart_id', $cart->id)->orderBy('variant_id')->get();
        $original = array_column($group['items'], null, 'id');
        if ($items->isEmpty() || count($original) !== $items->count()) {
            $this->problem(409, 'CHECKOUT_SNAPSHOT_STALE');
        }
        $lines = $changes = [];
        $subtotal = $weight = 0;
        foreach ($items as $item) {
            $previous = $original[PublicId::encode('citem', $item->id)] ?? [];
            $oldMoney = is_array($previous) ? ($previous['unit_price'] ?? null) : null;
            $oldPrice = is_array($oldMoney) ? ($oldMoney['amount_minor'] ?? null) : null;
            if (! is_array($previous) || ($previous['quantity'] ?? null) !== $item->qty || ! is_int($oldPrice)) {
                $this->problem(409, 'CHECKOUT_SNAPSHOT_STALE');
            }
            $variant = $this->variants->find($item->variant_id);
            if ($variant === null || $variant->mode !== 'rts') {
                $this->problem(422, 'CART_ITEM_UNAVAILABLE');
            }
            if ($variant->weightGrams === null || $variant->weightGrams < 1) {
                $this->problem(422, 'SHIPPING_WEIGHT_MISSING');
            }
            $code = PublicId::encode('var', $variant->id);
            if ($variant->price !== $oldPrice) {
                $changes[] = ['kind' => 'variant_price', 'variant_id' => $code, 'from' => $oldPrice, 'to' => $variant->price];
            }
            $lines[] = ['variant_id' => $variant->id, 'qty' => $item->qty, 'name' => $variant->name, 'sku' => $variant->sku, 'price' => $variant->price, 'old_price' => $oldPrice];
            $subtotal += $variant->price * $item->qty;
            $weight += $variant->weightGrams * $item->qty;
        }
        try {
            $rates = $this->shipping->rates(new ShippingRateRequest(Config::string('checkout.origin_country'), $address->province, $address->city, $address->district, $address->postal_code, $weight, 'paket_besar', fresh: true));
        } catch (InvalidDestination) {
            $this->problem(422, 'ADDRESS_INVALID');
        } catch (ProviderUnavailable) {
            $this->problem(503, 'SHIPPING_PROVIDER_UNAVAILABLE');
        }
        foreach ($rates as $rate) {
            if ($rate->courierCode === $courier && $rate->serviceCode === $service) {
                if ($rate->amountMinor !== $snapshot['shipping_estimate']) {
                    $changes[] = ['kind' => 'shipping', 'from' => $snapshot['shipping_estimate'], 'to' => $rate->amountMinor];
                }

                if ($session->expires_at->lte(now())) {
                    $this->problem(422, 'CHECKOUT_SESSION_EXPIRED');
                }

                return new ReadyStockQuote($session->id, $address, $courier, $service, $lines, $subtotal, $rate->amountMinor, $changes);
            }
        }

        $this->problem(409, 'SHIPPING_SELECTION_INVALID');
    }

    public function convert(int $sessionId, int $orderId): void
    {
        CheckoutSession::query()->whereKey($sessionId)->update(['status' => 'converted', 'converted_order_id' => $orderId]);
    }

    public function consumePaidItems(int $orderId): void
    {
        $session = CheckoutSession::query()->where('converted_order_id', $orderId)->where('status', 'converted')->first();
        if ($session === null) {
            return;
        }
        $cart = CartRecord::query()->whereKey($session->cart_id)->where('status', 'active')->lockForUpdate()->first();
        $group = data_get($session->snapshot, 'cart.groups.0.items');
        if ($cart === null || ! is_array($group)) {
            return;
        }

        foreach ($group as $line) {
            if (! is_array($line) || ! is_string($line['id'] ?? null) || ! is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                continue;
            }
            $item = CartItem::query()->where('cart_id', $cart->id)->find(PublicId::decode($line['id'], 'citem'));
            if ($item === null) {
                continue;
            }
            if ($item->qty <= $line['quantity']) {
                $item->delete();
            } else {
                $item->qty -= $line['quantity'];
                $item->save();
            }
        }
    }

    public function create(int $user, string $addressId, string $hash): CheckoutSession
    {
        return DB::transaction(function () use ($user, $addressId, $hash): CheckoutSession {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            $address = $this->addresses->owned($user, $addressId);
            $view = $this->cart->read($user);
            if (! hash_equals($view->items_hash, $hash)) {
                $this->problem(409, 'CHECKOUT_SNAPSHOT_STALE');
            }
            if ($view->groups === []) {
                $this->problem(422, 'CART_EMPTY');
            }
            foreach ($view->groups as $group) {
                foreach ($group->items as $line) {
                    if ($line->unavailable) {
                        $this->problem(422, 'CART_ITEM_UNAVAILABLE');
                    }
                }
            }
            $snapshot = json_decode(json_encode(['cart' => $view, 'address' => $address, 'shipping_selection' => [], 'shipping_estimate' => null, 'total' => null], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $session = CheckoutSession::query()->create(['code' => 'cs_'.Str::ulid(), 'user_id' => $user, 'cart_id' => $this->cart->active($user)?->id, 'subtotal' => $view->subtotal->amount_minor, 'snapshot' => $snapshot, 'expires_at' => now()->addMinutes(max(1, Config::integer('checkout.session_ttl_minutes')))]);

            return $session->refresh();
        });
    }

    public function owned(int $user, string $id): CheckoutSession
    {
        $session = CheckoutSession::query()->where('user_id', $user)->where('code', $id)->firstOrFail();
        if ($session->expires_at->isPast() && $session->status === 'open') {
            CheckoutSession::query()->whereKey($session->id)->where('status', 'open')->update(['status' => 'expired']);
            $session->refresh();
        }

        return $session;
    }

    /** @return array<string,mixed> */
    public function read(CheckoutSession $s): array
    {
        $estimate = $s->snapshot['shipping_estimate'] ?? null;

        return ['id' => $s->code, 'status' => $s->status, 'expires_at' => $s->expires_at->toIso8601String(), 'subtotal' => new Money($s->subtotal), 'shipping_estimate' => is_int($estimate) ? new Money($estimate) : null, 'total' => is_int($estimate) ? new Money($s->subtotal + $estimate) : null, 'snapshot' => $s->snapshot, 'fake_payment_available' => FakePaymentAvailability::enabled()];
    }

    /** @return array<string,mixed> */
    public function options(int $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id): array {
            $session = CheckoutSession::query()->where('user_id', $user)->where('code', $id)->lockForUpdate()->firstOrFail();
            if ($session->expires_at->isPast() || $session->status !== 'open') {
                $this->problem(422, 'CHECKOUT_SESSION_EXPIRED');
            }
            $snapshot = $session->snapshot;
            $address = $snapshot['address'] ?? null;
            $cart = $snapshot['cart'] ?? null;
            if (! is_array($address) || ! is_array($cart) || ! is_array($cart['groups'] ?? null)) {
                throw new \UnexpectedValueException('Invalid checkout snapshot');
            }
            $quoted = [];
            $estimate = 0;
            $selection = [];
            foreach ($cart['groups'] as $index => $group) {
                if (! is_array($group) || ! is_array($group['items'] ?? null)) {
                    throw new \UnexpectedValueException('Invalid checkout group');
                }
                $weight = 0;
                foreach ($group['items'] as $line) {
                    if (! is_array($line) || ! is_int($line['weight_g'] ?? null) || $line['weight_g'] < 1 || ! is_int($line['quantity'] ?? null)) {
                        $this->problem(422, 'SHIPPING_WEIGHT_MISSING');
                    }
                    $weight += $line['weight_g'] * $line['quantity'];
                }
                try {
                    $rates = $this->shipping->rates(new ShippingRateRequest(Config::string('checkout.origin_country'), $this->text($address, 'province'), $this->text($address, 'city'), $this->text($address, 'district'), $this->text($address, 'postal_code'), $weight, 'paket_besar'));
                } catch (InvalidDestination) {
                    $this->problem(422, 'ADDRESS_INVALID');
                } catch (ProviderUnavailable) {
                    $this->problem(503, 'SHIPPING_PROVIDER_UNAVAILABLE');
                }
                if ($rates !== []) {
                    $cheapest = $rates[0];
                    foreach ($rates as $rate) {
                        if ($rate->amountMinor < $cheapest->amountMinor) {
                            $cheapest = $rate;
                        }
                    }
                    $estimate += $cheapest->amountMinor;
                    $selection[] = ['group_index' => $index, 'courier_code' => $cheapest->courierCode, 'service_code' => $cheapest->serviceCode];
                }
                $quoted[] = ['group_index' => $index, 'mode' => $group['mode'] ?? 'rts', 'eta' => $group['eta'] ?? null, 'options' => $rates];
            }
            $snapshot['shipping_options'] = $quoted;
            $snapshot['shipping_selection'] = $selection;
            $complete = count($selection) === count($quoted);
            $snapshot['shipping_estimate'] = $complete ? $estimate : null;
            $snapshot['total'] = $complete ? $session->subtotal + $estimate : null;
            $session->snapshot = $snapshot;
            $session->shipping_estimate = $complete ? $estimate : 0;
            $session->save();

            return ['groups' => $quoted, 'checkout' => $this->read($session)];
        });
    }

    /**
     * @param  array<mixed>  $selections
     * @return array<string,mixed>
     */
    public function select(int $user, string $id, array $selections): array
    {
        return DB::transaction(function () use ($user, $id, $selections): array {
            $session = CheckoutSession::query()->where('user_id', $user)->where('code', $id)->lockForUpdate()->firstOrFail();
            if ($session->expires_at->isPast() || $session->status !== 'open') {
                $this->problem(422, 'CHECKOUT_SESSION_EXPIRED');
            }
            $snapshot = $session->snapshot;
            $quotes = $snapshot['shipping_options'] ?? null;
            if (! is_array($quotes) || $quotes === []) {
                $this->problem(409, 'SHIPPING_QUOTES_REQUIRED');
            }
            if (count($selections) !== count($quotes)) {
                $this->problem(422, 'SHIPPING_SELECTION_INCOMPLETE');
            }
            $chosen = [];
            $estimate = 0;
            foreach ($selections as $selection) {
                if (! is_array($selection) || count($selection) !== 3 || ! is_int($selection['group_index'] ?? null) || ! is_string($selection['courier_code'] ?? null) || ! is_string($selection['service_code'] ?? null)) {
                    $this->problem(422, 'SHIPPING_SELECTION_INVALID');
                }
                $index = $selection['group_index'];
                if (isset($chosen[$index]) || ! isset($quotes[$index]) || ! is_array($quotes[$index]) || ! is_array($quotes[$index]['options'] ?? null)) {
                    $this->problem(409, 'SHIPPING_SELECTION_INVALID');
                }
                $matched = null;
                foreach ($quotes[$index]['options'] as $rate) {
                    if (is_array($rate) && ($rate['courierCode'] ?? null) === $selection['courier_code'] && ($rate['serviceCode'] ?? null) === $selection['service_code']) {
                        $matched = $rate;
                        break;
                    }
                }
                if ($matched === null || ! is_int($matched['amountMinor'] ?? null) || $matched['amountMinor'] < 0 || ($matched['currency'] ?? null) !== 'IDR') {
                    $this->problem(409, 'SHIPPING_SELECTION_INVALID');
                }
                $estimate += $matched['amountMinor'];
                $chosen[$index] = ['group_index' => $index, 'courier_code' => $selection['courier_code'], 'service_code' => $selection['service_code']];
            }
            ksort($chosen);
            $snapshot['shipping_selection'] = array_values($chosen);
            $snapshot['shipping_estimate'] = $estimate;
            $snapshot['total'] = $session->subtotal + $estimate;
            $session->snapshot = $snapshot;
            $session->shipping_estimate = $estimate;
            $session->save();

            return $this->read($session);
        });
    }

    /** @param array<mixed> $data */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value)) {
            throw new \UnexpectedValueException('Invalid snapshot address');
        }

        return $value;
    }

    private function problem(int $status, string $code): never
    {
        $type = match ($code) {
            'CHECKOUT_SNAPSHOT_STALE' => 'snapshot-stale','ADDRESS_INVALID' => 'address-invalid',default => strtolower(str_replace('_', '-', $code))
        };
        $problem = ApiProblem::make($code === 'ADDRESS_INVALID' ? 'order' : ($status === 503 ? 'external' : 'checkout'), $type, $code, $status, $code, $status === 503);
        if ($code === 'ADDRESS_INVALID') {
            $problem = $problem->withError(['pointer' => '/shipping_address_id', 'code' => 'invalid', 'detail' => 'Tujuan tidak dapat dilayani.']);
        }
        $request = request();
        $requestId = $request->attributes->get('request_id');
        throw new HttpResponseException($problem->forRequest($request, is_string($requestId) ? $requestId : '')->toResponse());
    }
}
