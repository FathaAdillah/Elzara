<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Commands;

use App\Modules\Billing\Contracts\CheckoutInvoices;
use App\Modules\Cart\Contracts\ReadyStockCheckout;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Orders\Application\CheckoutProblem;
use App\Modules\Payments\Contracts\CheckoutPaymentIntents;
use App\Modules\Payments\Contracts\FakePaymentAvailability;
use App\Platform\Domain\Statuses\FulfillmentStatus;
use App\Platform\Domain\Statuses\OrderStatus;
use App\Platform\Domain\Statuses\PaymentStatus;
use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\StateMachineRunner;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ResolveFakeCheckout
{
    public function __construct(
        private readonly CheckoutPaymentIntents $payments,
        private readonly StockReservations $stock,
        private readonly CheckoutInvoices $invoices,
        private readonly ReadyStockCheckout $checkout,
        private readonly StateMachineRunner $states,
    ) {}

    /** @return array{order_id:string, status:string} */
    public function capture(int $user, string $orderCode, string $paymentCode): array
    {
        if (! FakePaymentAvailability::enabled()) {
            CheckoutProblem::raise(409, 'FAKE_PAYMENT_DISABLED');
        }

        return DB::transaction(function () use ($user, $orderCode, $paymentCode): array {
            $order = DB::table('orders')->where(['code' => $orderCode, 'user_id' => $user])->lockForUpdate()->firstOrFail();
            $id = $order->id;
            if (! is_int($id)) {
                throw new LogicException('Invalid checkout order ID.');
            }
            $intents = $this->payments->lockForOrder($id);
            $intent = count($intents) === 1 ? $intents[0] : null;
            if ($intent === null || $intent->provider !== 'fake' || $intent->code !== $paymentCode) {
                CheckoutProblem::raise(404, 'PAYMENT_INTENT_NOT_FOUND');
            }

            $result = ['order_id' => $orderCode, 'status' => OrderStatus::Paid->value];
            if ($order->order_status === OrderStatus::Paid->value && $intent->status === 'captured') {
                return $result;
            }

            $now = now();
            if ($intent->status === 'expired' || $intent->expiresAt->lte($now)) {
                CheckoutProblem::raise(409, 'PAYMENT_INTENT_EXPIRED');
            }
            if ($intent->status !== 'requires_action' || $order->order_status !== OrderStatus::AwaitingPayment->value || $order->payment_status !== PaymentStatus::Unpaid->value) {
                CheckoutProblem::raise(409, 'PAYMENT_INTENT_NOT_ACTIONABLE');
            }

            $this->stock->commit($id, $now);
            $this->payments->capture($intent, $now);
            $this->invoices->pay($intent->invoiceId, $now);
            $reason = 'Pembayaran simulasi berhasil.';
            $this->states->transition('orders', $id, Axis::Order, OrderStatus::Paid->value, OrderStatus::AwaitingPayment->value, $reason, $user);
            $this->states->transition('orders', $id, Axis::Payment, PaymentStatus::Paid->value, PaymentStatus::Unpaid->value, $reason, $user);
            DB::table('orders')->where('id', $id)->update(['paid_at' => $now]);
            $this->checkout->consumePaidItems($id);

            return $result;
        });
    }

    public function expire(int $limit): int
    {
        $now = now();
        $expired = 0;
        foreach ($this->stock->dueOrders($now, $limit) as $id) {
            $expired += (int) DB::transaction(function () use ($id, $now): bool {
                $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
                if ($order === null || $order->order_status !== OrderStatus::AwaitingPayment->value || $order->payment_status !== PaymentStatus::Unpaid->value) {
                    return false;
                }
                $intents = $this->payments->lockForOrder($id);
                $intent = count($intents) === 1 ? $intents[0] : null;
                if ($intent === null || $intent->provider !== 'fake' || $intent->status !== 'requires_action' || $intent->expiresAt->gt($now)) {
                    return false;
                }

                $this->stock->expire($id, $now);
                $this->payments->expire($intent, $now);
                $this->invoices->void($intent->invoiceId, $now);
                $reason = 'Reservasi checkout kedaluwarsa.';
                $this->states->transition('orders', $id, Axis::Order, OrderStatus::Expired->value, OrderStatus::AwaitingPayment->value, $reason);
                $this->states->transition('orders', $id, Axis::Fulfillment, FulfillmentStatus::Unprocessed->value, FulfillmentStatus::Reserved->value, $reason);

                return true;
            }, 3);
        }

        return $expired;
    }
}
