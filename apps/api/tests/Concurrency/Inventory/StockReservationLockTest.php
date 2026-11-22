<?php

declare(strict_types=1);

namespace Tests\Concurrency\Inventory;

use App\Modules\Inventory\Contracts\InsufficientStock;
use App\Modules\Inventory\Contracts\StockReservations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StockReservationLockTest extends TestCase
{
    public function test_only_one_checkout_can_reserve_the_last_unit(): void
    {
        $schema = 'reservation_lock_'.bin2hex(random_bytes(8));
        $primary = DB::getDefaultConnection();
        $secondary = 'reservation_contender';
        DB::statement("CREATE SCHEMA {$schema}");

        try {
            foreach (['warehouses', 'stock_items', 'stock_reservations', 'stock_movements', 'outbox_events'] as $table) {
                DB::statement("CREATE TABLE {$schema}.{$table} (LIKE public.{$table} INCLUDING ALL)");
            }
            DB::statement("SET search_path TO {$schema}");
            config(['database.connections.'.$secondary => config('database.connections.'.$primary), 'checkout.warehouse_code' => 'RACE']);
            DB::connection($secondary)->statement("SET search_path TO {$schema}");
            DB::connection($secondary)->statement("SET lock_timeout TO '200ms'");
            $warehouse = DB::table('warehouses')->insertGetId(['code' => 'RACE', 'name' => 'Race', 'kind' => 'warehouse']);
            DB::table('stock_items')->insert(['warehouse_id' => $warehouse, 'variant_id' => 1, 'on_hand' => 1]);
            $stock = app(StockReservations::class);
            $expires = now()->addMinutes(10);
            $contend = static function () use ($stock, $expires, $primary, $secondary): void {
                DB::setDefaultConnection($secondary);
                try {
                    DB::transaction(fn () => $stock->hold(2, [['id' => 2, 'variant' => 1, 'qty' => 1]], $expires));
                } finally {
                    DB::setDefaultConnection($primary);
                }
            };

            DB::transaction(function () use ($stock, $expires, $contend): void {
                $stock->hold(1, [['id' => 1, 'variant' => 1, 'qty' => 1]], $expires);
                try {
                    $contend();
                    $this->fail('The second checkout must wait on the stock row.');
                } catch (QueryException $exception) {
                    $this->assertSame('55P03', $exception->errorInfo[0]);
                }
            });

            try {
                $contend();
                $this->fail('The winning checkout has reserved all available stock.');
            } catch (InsufficientStock) {
                $this->assertSame(0, DB::table('stock_items')->value('available_to_sell'));
            }
            $this->assertSame(1, DB::table('stock_items')->value('reserved'));
            $this->assertSame([1], DB::table('stock_reservations')->pluck('order_id')->all());
            $this->assertSame(1, DB::table('stock_movements')->count());
            $this->assertSame(1, DB::table('outbox_events')->where('event_name', 'StockReserved')->count());
        } finally {
            DB::setDefaultConnection($primary);
            DB::purge($secondary);
            DB::statement('RESET search_path');
            DB::statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
}
