<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Application;

use App\Modules\PreOrder\Contracts\BatchCheckout;
use App\Modules\PreOrder\Contracts\BatchOffer;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\OutboxWriter;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabaseBatchCheckout implements BatchCheckout
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function offer(int $variant): BatchOffer
    {
        $now = now();
        $row = DB::table('preorder_batch_items as i')->join('preorder_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.variant_id', $variant)->where('i.is_active', true)->where('i.status', 'open')->where('b.status', 'open')
            ->where('b.order_window_open_at', '<=', $now)->where('b.order_window_close_at', '>', $now)
            ->where(fn (Builder $q) => $q->whereNull('i.opens_at')->orWhere('i.opens_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('i.closes_at')->orWhere('i.closes_at', '>', $now))
            ->where(fn (Builder $q) => $q->whereNull('i.price_valid_until')->orWhere('i.price_valid_until', '>', $now))
            ->where('i.currency_code', 'IDR')->where('b.currency_code', 'IDR')
            ->orderBy('b.order_window_close_at')->orderBy('i.id')
            ->first(['i.id', 'i.batch_id', 'i.price', 'b.deposit_kind', 'b.deposit_value']);
        if ($row === null) {
            BatchProblem::raise('BATCH_NOT_OPEN');
        }

        return new BatchOffer(BatchValue::integer($row->id), BatchValue::integer($row->batch_id), BatchValue::integer($row->price), BatchValue::text($row->deposit_kind), BatchValue::integer($row->deposit_value));
    }

    public function enroll(BatchOffer $offer, int $user, int $order, int $item, int $quantity, CarbonInterface $expires): int
    {
        return DB::transaction(function () use ($offer, $user, $order, $item, $quantity, $expires): int {
            $batch = DB::table('preorder_batches')->where('id', $offer->batchId)->lockForUpdate()->first();
            $slot = DB::table('preorder_batch_items')->where('id', $offer->id)->lockForUpdate()->first();
            $now = now();
            if ($quantity < 1 || $batch === null || $slot === null || $batch->status !== 'open' || $slot->status !== 'open' || ! $slot->is_active
                || BatchValue::time($batch->order_window_open_at)->gt($now) || BatchValue::time($batch->order_window_close_at)->lte($now)
                || ($slot->opens_at !== null && BatchValue::time($slot->opens_at)->gt($now)) || ($slot->closes_at !== null && BatchValue::time($slot->closes_at)->lte($now))
                || ($slot->price_valid_until !== null && BatchValue::time($slot->price_valid_until)->lte($now))) {
                BatchProblem::raise('BATCH_NOT_OPEN');
            }
            if (BatchValue::integer($slot->price) !== $offer->price || $batch->deposit_kind !== $offer->depositKind || BatchValue::integer($batch->deposit_value) !== $offer->depositValue) {
                BatchProblem::raise('BATCH_NOT_OPEN');
            }
            $used = (int) DB::table('preorder_enrollments')->where('batch_item_id', $offer->id)->where('user_id', $user)
                ->whereNotIn('state', ['expired', 'released', 'cancelled'])->sum('qty');
            if ($slot->max_per_account !== null && $used + $quantity > BatchValue::integer($slot->max_per_account)) {
                BatchProblem::raise('BATCH_ACCOUNT_LIMIT_EXCEEDED');
            }
            $updated = DB::table('preorder_batch_items')->where('id', $offer->id)->where('status', 'open')->where('is_active', true)
                ->whereRaw('reserved_qty + ? <= quota', [$quantity])
                ->where(fn (Builder $q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', $now))
                ->where(fn (Builder $q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', $now))
                ->increment('reserved_qty', $quantity, ['updated_at' => $now]);
            if ($updated !== 1) {
                BatchProblem::raise('BATCH_QUOTA_EXHAUSTED');
            }
            $deposit = $offer->deposit($quantity);
            DB::table('preorder_enrollments')->insert([
                'batch_id' => $offer->batchId, 'batch_item_id' => $offer->id, 'user_id' => $user,
                'order_id' => $order, 'order_item_id' => $item, 'qty' => $quantity, 'state' => 'reserved',
                'slot_reserved_at' => $now, 'slot_expires_at' => $expires, 'deposit_amount' => $deposit,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return $deposit;
        });
    }

    public function capture(int $order, CarbonInterface $now): int
    {
        $rows = DB::table('preorder_enrollments')->where('order_id', $order)->where('state', 'reserved')->orderBy('id')->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            throw new LogicException('Missing reserved preorder enrollment.');
        }
        foreach ($rows as $row) {
            if (BatchValue::time($row->slot_expires_at)->lte($now)) {
                throw new LogicException('Preorder enrollment expired.');
            }
            DB::table('preorder_enrollments')->where('id', $row->id)->update(['state' => 'deposit_paid', 'updated_at' => $now]);
            $this->outbox->record(new DomainEvent('DepositPaid', 'preorder_enrollment', BatchValue::integer($row->id), [
                'preorder_enrollment_id' => $row->id, 'order_id' => $order, 'amount' => $row->deposit_amount, 'due_balance_at' => null,
            ]));
        }

        $total = 0;
        foreach ($rows as $row) {
            $total += BatchValue::integer($row->deposit_amount);
        }

        return $total;
    }

    public function dueOrders(CarbonInterface $now, int $limit): array
    {
        $orders = DB::table('preorder_enrollments')->where('state', 'reserved')->where('slot_expires_at', '<=', $now)
            ->groupBy('order_id')->orderByRaw('min(slot_expires_at)')->orderBy('order_id')->limit($limit)
            ->pluck('order_id');
        $ids = [];
        foreach ($orders as $id) {
            $ids[] = BatchValue::integer($id);
        }

        return $ids;
    }

    public function expire(int $order, CarbonInterface $now): void
    {
        foreach (DB::table('preorder_enrollments')->where('order_id', $order)->where('state', 'reserved')->orderBy('batch_item_id')->lockForUpdate()->get() as $row) {
            if (DB::table('preorder_batch_items')->where('id', $row->batch_item_id)->where('reserved_qty', '>=', $row->qty)
                ->decrement('reserved_qty', BatchValue::integer($row->qty), ['updated_at' => $now]) !== 1) {
                throw new LogicException('Missing preorder quota reservation.');
            }
            DB::table('preorder_enrollments')->where('id', $row->id)->update(['state' => 'expired', 'updated_at' => $now]);
        }
    }
}
