<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Commands;

use App\Modules\Billing\Contracts\CheckoutInvoices;
use App\Modules\Cart\Contracts\ReadyStockCheckout;
use App\Modules\Inventory\Contracts\InsufficientStock;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Orders\Application\CheckoutProblem;
use App\Modules\Payments\Contracts\CheckoutPaymentIntents;
use App\Modules\Payments\Contracts\FakePaymentAvailability;
use App\Modules\PreOrder\Contracts\BatchCheckout;
use App\Platform\Domain\Statuses\FulfillmentStatus;
use App\Platform\Domain\Statuses\OrderStatus;
use App\Platform\Domain\Statuses\OrderType;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\OutboxWriter;
use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\FulfillmentLane;
use App\Platform\StateMachine\StateMachineRunner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PlaceOrder
{
    public function __construct(
        private readonly ReadyStockCheckout $checkout,
        private readonly StockReservations $stock,
        private readonly CheckoutInvoices $invoices,
        private readonly CheckoutPaymentIntents $payments,
        private readonly OutboxWriter $outbox,
        private readonly StateMachineRunner $states,
        private readonly BatchCheckout $batches,
    ) {}

    /** @return array<string,mixed> */
    public function handle(int $actor, string $session, string $address, ?string $note): array
    {
        if (! FakePaymentAvailability::enabled()) {
            CheckoutProblem::raise(409, 'FAKE_PAYMENT_DISABLED');
        }

        try {
            return DB::transaction(function () use ($actor, $session, $address, $note): array {
                $quote = $this->checkout->prepare($actor, $session, $address);
                $preorder = $quote->mode === 'pre_order';
                $lines = $quote->lines;
                $offers = [];
                $subtotal = $quote->subtotal;
                $changes = $quote->changes;
                if ($preorder) {
                    $subtotal = 0;
                    foreach ($lines as &$line) {
                        $offer = $this->batches->offer($line['variant_id']);
                        $offers[$line['variant_id']] = $offer;
                        $line['price'] = $offer->price;
                        $subtotal += $offer->price * $line['qty'];
                        if ($offer->price !== $line['old_price']) {
                            $changes[] = ['kind' => 'batch_price', 'variant_id' => $line['variant_id'], 'from' => $line['old_price'], 'to' => $offer->price];
                        }
                    }
                    unset($line);
                }
                $total = $subtotal + $quote->shipping;
                if ($total <= 0) {
                    CheckoutProblem::raise(422, 'CHECKOUT_TOTAL_INVALID');
                }
                $now = now();
                $expires = $now->copy()->startOfSecond()->addMinutes(max(1, Config::integer('checkout.reservation_ttl_minutes')));
                $code = 'ord_'.Str::ulid();
                $id = DB::table('orders')->insertGetId([
                    'code' => $code, 'user_id' => $actor, 'checkout_session_id' => $quote->sessionId,
                    'order_type' => $preorder ? OrderType::Preorder->value : OrderType::ReadyStock->value,
                    'fulfillment_lane' => $preorder ? FulfillmentLane::ImportPreOrder->value : FulfillmentLane::ReadyToShip->value,
                    'subtotal' => $subtotal, 'shipping_total' => $quote->shipping, 'total' => $total, 'notes' => $note,
                    'fulfillment_status' => FulfillmentStatus::Unprocessed->value,
                    'shipping_snapshot' => json_encode(['address' => $quote->address, 'courier' => $quote->courier, 'service' => $quote->service], JSON_THROW_ON_ERROR),
                    'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);

                $orderItems = [];
                $deposit = 0;
                foreach ($lines as $line) {
                    $item = DB::table('order_items')->insertGetId([
                        'order_id' => $id, 'variant_id' => $line['variant_id'], 'qty' => $line['qty'], 'unit_price' => $line['price'],
                        'product_name' => $line['name'], 'sku' => $line['sku'], 'created_at' => $now, 'updated_at' => $now,
                        'price_snapshot' => json_encode(['checkout_price' => $line['old_price'], 'placed_price' => $line['price']], JSON_THROW_ON_ERROR),
                    ]);
                    $orderItems[] = ['id' => $item, 'variant' => $line['variant_id'], 'qty' => $line['qty']];
                    if ($preorder) {
                        $deposit += $this->batches->enroll($offers[$line['variant_id']], $actor, $id, $item, $line['qty'], $expires);
                    }
                }
                if (! $preorder) {
                    $this->stock->hold($id, $orderItems, $expires);
                }
                $this->states->transition('orders', $id, Axis::Fulfillment, $preorder ? FulfillmentStatus::AwaitingBatch->value : FulfillmentStatus::Reserved->value, FulfillmentStatus::Unprocessed->value, $preorder ? 'Kuota checkout direservasi.' : 'Stok checkout direservasi.', $actor);
                $payable = $preorder ? $deposit : $total;
                $invoiceType = $preorder ? 'deposit' : 'balance';
                $invoice = $this->invoices->issue($id, $payable, $expires, $invoiceType);
                $intent = $this->payments->issue($id, $invoice['id'], $payable, $expires);
                $this->checkout->convert($quote->sessionId, $id);
                $expiry = $expires->toIso8601String();
                $money = ['amount_minor' => $total, 'currency' => 'IDR'];
                $this->outbox->record(new DomainEvent('OrderPlaced', 'order', $id, [
                    'order_id' => $id, 'order_type' => $preorder ? OrderType::Preorder->value : OrderType::ReadyStock->value, 'customer_id' => $actor,
                    'grand_total' => $total, 'currency' => 'IDR', 'item_count' => count($orderItems), 'expires_at' => $expiry,
                ]));

                return [
                    'id' => $code, 'status' => OrderStatus::AwaitingPayment->value, 'total' => $money, 'created_at' => $now->toIso8601String(),
                    'reservation_expires_at' => $expiry, 'invoice' => ['id' => $invoice['code'], 'type' => $invoiceType, 'due_at' => $expiry, 'total' => ['amount_minor' => $payable, 'currency' => 'IDR']],
                    'payment_intent' => ['id' => $intent->code, 'provider' => $intent->provider, 'expires_at' => $expiry, 'status' => $intent->status],
                    'repriced' => $changes !== [], 'changes_from_snapshot' => $changes,
                ];
            });
        } catch (InsufficientStock) {
            CheckoutProblem::raise(422, 'INSUFFICIENT_STOCK');
        }
    }
}
