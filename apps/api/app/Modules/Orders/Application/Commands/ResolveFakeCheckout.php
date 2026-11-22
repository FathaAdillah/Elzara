<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Commands;

use App\Modules\Billing\Contracts\CheckoutInvoices;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Orders\Application\CheckoutProblem;
use App\Modules\Payments\Contracts\CheckoutPaymentIntents;
use App\Modules\Payments\Contracts\FakePaymentAvailability;
use Illuminate\Support\Facades\DB;
use LogicException;

final class ResolveFakeCheckout
{
    public function __construct(
        private readonly CheckoutPaymentIntents $payments,
        private readonly StockReservations $stock,
        private readonly CheckoutInvoices $invoices,
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

            $result = ['order_id' => $orderCode, 'status' => 'paid'];
            if ($order->order_status === 'paid' && $intent->status === 'captured') {
                return $result;
            }

            $now = now();
            if ($intent->status === 'expired' || $intent->expiresAt->lte($now)) {
                CheckoutProblem::raise(409, 'PAYMENT_INTENT_EXPIRED');
            }
            if ($intent->status !== 'requires_action' || $order->order_status !== 'awaiting_payment' || $order->payment_status !== 'unpaid') {
                CheckoutProblem::raise(409, 'PAYMENT_INTENT_NOT_ACTIONABLE');
            }

            $this->stock->commit($id, $now);
            $this->payments->capture($intent, $now);
            $this->invoices->pay($intent->invoiceId, $now);
            $this->finish($id, ['order_status' => 'paid', 'payment_status' => 'paid', 'paid_at' => $now]);

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
                if ($order === null || $order->order_status !== 'awaiting_payment' || $order->payment_status !== 'unpaid') {
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
                $this->finish($id, ['order_status' => 'expired', 'fulfillment_status' => 'unprocessed']);

                return true;
            }, 3);
        }

        return $expired;
    }

    /** @param array<string,mixed> $values */
    private function finish(int $id, array $values): void
    {
        if (DB::table('orders')->where('id', $id)->where('order_status', 'awaiting_payment')->where('payment_status', 'unpaid')
            ->update($values + ['updated_at' => now()]) !== 1) {
            throw new LogicException('Checkout order changed while locked.');
        }
    }
}
