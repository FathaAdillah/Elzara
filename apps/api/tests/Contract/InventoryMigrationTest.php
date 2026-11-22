<?php

declare(strict_types=1);

namespace Tests\Contract;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class InventoryMigrationTest extends TestCase
{
    public function test_stock_constraints_generated_availability_ledger_and_rollback(): void
    {
        DB::beginTransaction();
        try {
            $schema = 'inventory_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET LOCAL search_path TO {$schema}, public");
            DB::statement('CREATE TABLE users (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            DB::statement('CREATE TABLE product_variants (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            DB::statement('CREATE TABLE orders (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            DB::statement('CREATE TABLE order_items (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            $files = glob(app_path('Modules/Inventory/Infrastructure/Database/Migrations/*.php'));
            $this->assertCount(4, $files);
            foreach ($files as $file) {
                (require $file)->up();
            }
            foreach (['warehouses', 'stock_items', 'stock_movements', 'stock_reservations'] as $table) {
                $columns = collect(Schema::getColumns($table))->keyBy('name');
                $this->assertSame('int8', $columns['id']['type_name']);
                $this->assertSame('timestamptz', $columns['created_at']['type_name']);
                $this->assertSame($table !== 'stock_movements', $columns->has('updated_at'));
            }
            $columns = collect(Schema::getColumns('stock_items'))->keyBy('name');
            foreach (['on_hand', 'reserved', 'damaged', 'quarantined', 'in_transit', 'available_to_sell', 'min_stock'] as $column) {
                $this->assertSame('int4', $columns[$column]['type_name']);
            }
            $generated = DB::selectOne("SELECT attgenerated FROM pg_attribute WHERE attrelid = 'stock_items'::regclass AND attname = 'available_to_sell'");
            $this->assertSame('s', $generated->attgenerated);
            $this->assertFalse(Schema::hasColumn('stock_items', 'unavailable'));
            $indexes = collect(Schema::getIndexes('stock_items'))->keyBy('name');
            $this->assertTrue($indexes['uq_stock_items_slot']['unique']);
            $this->assertSame(['warehouse_id', 'variant_id'], $indexes['uq_stock_items_slot']['columns']);
            $this->assertSame(['warehouse_id'], $indexes['idx_stock_items_warehouse']['columns']);
            $ledgerIndexes = collect(Schema::getIndexes('stock_movements'))->keyBy('name');
            $this->assertTrue($ledgerIndexes->has('idx_stock_movements_variant'));
            $this->assertSame(['ref_type', 'ref_id'], $ledgerIndexes['idx_stock_movements_ref']['columns']);
            $warehouse = DB::table('warehouses')->insertGetId(['code' => 'WH', 'name' => 'Warehouse', 'kind' => 'warehouse']);
            $variant = DB::table('product_variants')->insertGetId([]);
            $slot = ['warehouse_id' => $warehouse, 'variant_id' => $variant];
            $reservation = $slot + [
                'order_id' => DB::table('orders')->insertGetId([]),
                'order_item_id' => DB::table('order_items')->insertGetId([]),
                'qty' => 1,
                'expires_at' => now()->addMinute(),
            ];
            $this->rejects('23514', fn () => DB::table('stock_reservations')->insert(array_replace($reservation, ['qty' => 0])));
            $this->assertTrue(DB::table('stock_reservations')->insert($reservation));
            $this->rejects('23505', fn () => DB::table('stock_reservations')->insert($reservation));
            DB::table('stock_items')->insert($slot);
            $this->assertSame(0, DB::table('stock_items')->value('available_to_sell'));
            foreach (['on_hand', 'reserved', 'damaged', 'quarantined', 'in_transit'] as $component) {
                $this->rejects('23514', fn () => DB::table('stock_items')->update([$component => -1]));
            }
            $this->rejects('23514', fn () => DB::table('stock_items')->update(['reserved' => 1]));
            $this->rejects('23505', fn () => DB::table('stock_items')->insert($slot));
            $this->rejects('23503', fn () => DB::table('warehouses')->where('id', $warehouse)->delete());
            $this->rejects('23503', fn () => DB::table('product_variants')->where('id', $variant)->delete());
            DB::table('stock_items')->update(['on_hand' => 10, 'reserved' => 2, 'damaged' => 3, 'quarantined' => 1]);
            $this->assertSame(4, DB::table('stock_items')->value('available_to_sell'));
            DB::table('stock_items')->update(['damaged' => 4, 'quarantined' => 2]);
            $this->assertSame(2, DB::table('stock_items')->value('available_to_sell'));
            DB::table('stock_items')->update(['in_transit' => 5, 'reserved' => 9]);
            $this->assertSame(-5, DB::table('stock_items')->value('available_to_sell'));
            $this->rejects('23514', fn () => DB::table('stock_items')->update(['reserved' => 10]));
            $this->rejects('428C9', fn () => DB::table('stock_items')->update(['available_to_sell' => 99]));
            $movement = ['variant_id' => $variant, 'warehouse_id' => $warehouse, 'qty_delta' => 10, 'component' => 'on_hand', 'reason' => 'goods_receipt', 'occurred_at' => '2026-01-01 00:00:00+00'];
            $id = DB::table('stock_movements')->insertGetId($movement);
            $this->rejects('23514', fn () => DB::table('stock_movements')->insert(array_replace($movement, ['qty_delta' => 0])));
            $this->rejects('23514', fn () => DB::table('stock_movements')->insert(array_replace($movement, ['component' => 'unavailable'])));
            $this->rejects('23514', fn () => DB::table('stock_movements')->insert(array_replace($movement, ['reason' => 'unknown'])));
            $this->rejects('23514', fn () => DB::table('stock_movements')->where('id', $id)->update(['qty_delta' => 11]));
            $this->rejects('23514', fn () => DB::table('stock_movements')->where('id', $id)->delete());
            DB::table('stock_movements')->insert(array_replace($movement, ['qty_delta' => -10, 'reason' => 'goods_receipt_reversal', 'reverses_id' => $id]));
            $this->assertSame(0, (int) DB::table('stock_movements')->sum('qty_delta'));
            foreach (array_reverse($files) as $file) {
                (require $file)->down();
                $table = preg_replace('/^\\d+_Inventory_create_(.*)_table.php$/', '$1', basename($file));
                $this->assertFalse(Schema::hasTable("{$schema}.{$table}"));
            }
            $this->assertNull(DB::selectOne("SELECT to_regprocedure('{$schema}.reject_stock_movements_mutation()') AS function")->function);
        } finally {
            DB::rollBack();
        }
    }

    private function rejects(string $state, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("Expected {$state}");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
