<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

use App\Modules\Cart\Infrastructure\Models\CartItem;
use App\Modules\Cart\Infrastructure\Models\CartRecord;
use App\Modules\Catalog\Contracts\SellableVariants;
use App\Platform\Domain\PublicId;
use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class CartService
{
    public function __construct(private readonly SellableVariants $variants) {}

    public function expire(): int
    {
        return CartRecord::query()->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
    }

    public function active(int $user): ?CartRecord
    {
        return CartRecord::query()->where('user_id', $user)->where('status', 'active')->where('expires_at', '>', now())->first();
    }

    public function read(int $user): CartView
    {
        return $this->view($this->active($user));
    }

    public function add(int $user, string $variantId, int $quantity): CartView
    {
        try {
            $variant = PublicId::decode($variantId, 'var');
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return $this->mutate($user, function (CartRecord $cart) use ($variant, $quantity): void {
            $item = CartItem::query()->where('cart_id', $cart->id)->where('variant_id', $variant)->whereNull('batch_offer_id')->first();
            $qty = ($item->qty ?? 0) + $quantity;
            $sellable = $this->variants->find($variant);
            if ($sellable === null || $qty > 99 || ($sellable->mode === 'rts' && $qty > $sellable->available)) {
                $this->invalid('CART_ITEM_UNAVAILABLE');
            }
            if ($item === null) {
                $item = new CartItem(['cart_id' => $cart->id, 'variant_id' => $variant, 'unit_price_snapshot' => $sellable->price]);
            }
            $item->qty = $qty;
            $item->save();
        }, true);
    }

    public function change(int $user, string $id, ?int $quantity): CartView
    {
        try {
            $key = PublicId::decode($id, 'citem');
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        return $this->mutate($user, function (CartRecord $cart) use ($key, $quantity): void {
            $item = CartItem::query()->where('cart_id', $cart->id)->findOrFail($key);
            if ($quantity === null) {
                $item->delete();

                return;
            }
            $sellable = $this->variants->find($item->variant_id);
            if ($sellable === null || ($sellable->mode === 'rts' && $quantity > $sellable->available)) {
                $this->invalid('CART_ITEM_UNAVAILABLE');
            }
            $item->qty = $quantity;
            $item->save();
        });
    }

    /** @param \Closure(CartRecord):void $change */
    private function mutate(int $user, \Closure $change, bool $create = false): CartView
    {
        return DB::transaction(function () use ($user, $change, $create): CartView {
            DB::table('users')->where('id', $user)->lockForUpdate()->first();
            CartRecord::query()->where('user_id', $user)->where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
            $cart = CartRecord::query()->where('user_id', $user)->where('status', 'active')->where('expires_at', '>', now())->lockForUpdate()->first();
            if ($cart === null) {
                abort_unless($create, 404);
                $cart = CartRecord::query()->create(['user_id' => $user, 'expires_at' => now()->addDays(max(1, Config::integer('cart.ttl_days')))]);
            }
            $change($cart);
            $cart->expires_at = now()->addDays(max(1, Config::integer('cart.ttl_days')));
            $cart->save();

            return $this->view($cart);
        });
    }

    private function view(?CartRecord $cart): CartView
    {
        $groups = [];
        $total = 0;
        $hash = [];
        if ($cart !== null) {
            foreach (CartItem::query()->where('cart_id', $cart->id)->orderBy('variant_id')->orderBy('id')->get() as $item) {
                $v = $this->variants->find($item->variant_id);
                $price = $v->price ?? $item->unit_price_snapshot;
                $mode = $v->mode ?? 'rts';
                $eta = $v?->eta;
                $key = $mode.'|'.($eta ?? '');
                $groups[$key] ??= new CartGroup($mode, $eta);
                $line = new CartLine(PublicId::encode('citem', $item->id), PublicId::encode('var', $item->variant_id), $v->name ?? 'Produk tidak tersedia', $item->qty, new Money($price), new Money($price * $item->qty), $price !== $item->unit_price_snapshot, $v === null || ($mode === 'rts' && $v->available < $item->qty), $v?->weightGrams, $v?->label, $v?->sku);
                $groups[$key]->items[] = $line;
                $groups[$key]->subtotal = new Money($groups[$key]->subtotal->amount_minor + $line->line_total->amount_minor);
                $total += $line->line_total->amount_minor;
                $hash[] = [$line->variant_id, $line->quantity, $price, $mode, $eta];
            }
        }

        return new CartView($cart === null ? null : PublicId::encode('cart', $cart->id), array_values($groups), new Money($total), 'IDR', $cart?->expires_at->toIso8601String(), 'sha256:'.hash('sha256', json_encode($hash, JSON_THROW_ON_ERROR)));
    }

    private function invalid(string $code): never
    {
        $request = request();
        $requestId = $request->attributes->get('request_id');
        throw new HttpResponseException(ApiProblem::make('checkout', 'item-unavailable', 'Barang tidak tersedia atau jumlah melebihi stok', 422, $code)->forRequest($request, is_string($requestId) ? $requestId : '')->toResponse());
    }
}
