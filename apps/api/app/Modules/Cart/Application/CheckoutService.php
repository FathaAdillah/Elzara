<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

use App\Modules\Cart\Infrastructure\Models\CheckoutSession;
use App\Modules\Fulfillment\Contracts\InvalidDestination;
use App\Modules\Fulfillment\Contracts\ProviderUnavailable;
use App\Modules\Fulfillment\Contracts\ShippingRateProvider;
use App\Modules\Fulfillment\Contracts\ShippingRateRequest;
use App\Modules\Identity\Contracts\CustomerAddresses;
use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutService
{
    public function __construct(private readonly CartService $cart, private readonly CustomerAddresses $addresses, private readonly ShippingRateProvider $shipping) {}

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
            $snapshot = json_decode(json_encode(['cart' => $view, 'address' => $address], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $session = CheckoutSession::query()->create(['code' => 'cs_'.Str::ulid(), 'user_id' => $user, 'cart_id' => $this->cart->active($user)?->id, 'subtotal' => $view->subtotal->amount_minor, 'snapshot' => $snapshot, 'expires_at' => now()->addMinutes(max(1, Config::integer('checkout.session_ttl_minutes')))]);

            return $session->refresh();
        });
    }

    public function owned(int $user, string $id): CheckoutSession
    {
        $session = CheckoutSession::query()->where('user_id', $user)->where('code', $id)->firstOrFail();
        if ($session->expires_at->isPast() && $session->status === 'open') {
            $session->status = 'expired';
            $session->save();
        }

        return $session;
    }

    /** @return array<string,mixed> */
    public function read(CheckoutSession $s): array
    {
        return ['id' => $s->code, 'status' => $s->status, 'expires_at' => $s->expires_at->toIso8601String(), 'subtotal' => new Money($s->subtotal), 'shipping_estimate' => new Money($s->shipping_estimate), 'total' => new Money($s->subtotal + $s->shipping_estimate), 'snapshot' => $s->snapshot];
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
                    $estimate += min(array_map(fn ($r): int => $r->amountMinor, $rates));
                }
                $quoted[] = ['group_index' => $index, 'mode' => $group['mode'] ?? 'rts', 'eta' => $group['eta'] ?? null, 'options' => $rates];
            }
            $snapshot['shipping_options'] = $quoted;
            $session->snapshot = $snapshot;
            $session->shipping_estimate = $estimate;
            $session->save();

            return ['groups' => $quoted, 'checkout' => $this->read($session)];
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
