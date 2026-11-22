<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Contracts\InsufficientStock;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;

final class DatabaseStockReservations implements StockReservations
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function hold(int $orderId, array $orderItems, CarbonInterface $expires): void
    {
        $warehouse = DB::table('warehouses')->where('code', Config::string('checkout.warehouse_code'))
            ->where('is_active', true)->where('kind', 'warehouse')->value('id');
        if (! is_int($warehouse)) {
            throw new InsufficientStock;
        }

        usort($orderItems, fn (array $a, array $b): int => $a['variant'] <=> $b['variant']);
        foreach ($orderItems as $line) {
            $slot = ['warehouse_id' => $warehouse, 'variant_id' => $line['variant']];
            $changed = DB::table('stock_items')->where($slot)->where('available_to_sell', '>=', $line['qty'])
                ->increment('reserved', $line['qty'], ['updated_at' => now()]);
            if ($changed !== 1) {
                throw new InsufficientStock;
            }

            $id = DB::table('stock_reservations')->insertGetId($slot + [
                'order_id' => $orderId, 'order_item_id' => $line['id'], 'qty' => $line['qty'],
                'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->movement($slot, $line['qty'], $id, 'checkout_hold', now());
            $this->outbox->record(new DomainEvent('StockReserved', 'stock_reservation', $id, [
                'stock_reservation_id' => $id, 'order_id' => $orderId, 'warehouse_id' => $warehouse,
                'sku_id' => $line['variant'], 'quantity' => $line['qty'], 'expires_at' => $expires->toIso8601String(),
            ]));
        }
    }

    public function dueOrders(CarbonInterface $now, int $limit): array
    {
        $orders = DB::table('stock_reservations')->where('status', 'held')->where('expires_at', '<=', $now)
            ->groupBy('order_id')->orderByRaw('min(expires_at)')->orderBy('order_id')->limit($limit)->pluck('order_id');
        $ids = [];
        foreach ($orders as $id) {
            if (! is_int($id)) {
                throw new LogicException('Invalid reservation order ID.');
            }
            $ids[] = $id;
        }

        return $ids;
    }

    public function expire(int $order, CarbonInterface $now): void
    {
        foreach ($this->held($order) as $row) {
            $slot = ['warehouse_id' => $row->warehouse_id, 'variant_id' => $row->variant_id];
            if (DB::table('stock_items')->where($slot)->where('reserved', '>=', $row->qty)
                ->decrement('reserved', $row->qty, ['updated_at' => $now]) !== 1) {
                throw new LogicException('Missing stock for held reservation.');
            }
            DB::table('stock_reservations')->where('id', $row->id)->update(['status' => 'expired', 'released_at' => $now, 'updated_at' => $now]);
            $this->movement($slot, -$row->qty, $row->id, 'checkout_expiry', $now);
            $this->outbox->record(new DomainEvent('ReservationExpired', 'stock_reservation', $row->id, [
                'stock_reservation_id' => $row->id, 'order_id' => $order, 'sku_id' => $row->variant_id,
                'quantity' => $row->qty, 'released_at' => $now->toIso8601String(),
            ]));
        }
    }

    public function commit(int $order, CarbonInterface $now): void
    {
        $held = $this->held($order);
        if ($held === []) {
            throw new InsufficientStock;
        }
        foreach ($held as $row) {
            if (CarbonImmutable::parse($row->expires_at)->lte($now)) {
                throw new InsufficientStock;
            }
            DB::table('stock_reservations')->where('id', $row->id)->update(['status' => 'committed', 'committed_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @return list<object{id:int, variant_id:int, warehouse_id:int, qty:int, expires_at:string}> */
    private function held(int $order): array
    {
        /** @var list<object{id:int, variant_id:int, warehouse_id:int, qty:int, expires_at:string}> $rows */
        $rows = DB::table('stock_reservations')->where('order_id', $order)->where('status', 'held')
            ->orderBy('id')->lockForUpdate()->get()->all();

        return $rows;
    }

    /** @param array{warehouse_id:int, variant_id:int} $slot */
    private function movement(array $slot, int $delta, int $id, string $reference, CarbonInterface $now): void
    {
        DB::table('stock_movements')->insert($slot + [
            'qty_delta' => $delta, 'component' => 'reserved', 'reason' => 'adjustment',
            'ref_type' => $reference, 'ref_id' => $id, 'occurred_at' => $now,
        ]);
    }
}
