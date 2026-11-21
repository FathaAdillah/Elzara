<?php

// modul: Inventory | level: L4 | sprint: 3 | tier: 4 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('variant_id')->constrained('product_variants', indexName: 'fk_stock_movements_variant')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses', indexName: 'fk_stock_movements_warehouse')->restrictOnDelete();
            $table->integer('qty_delta');
            $table->string('component', 16);
            $table->string('reason', 28);
            $table->string('ref_type', 40)->nullable();
            $table->bigInteger('ref_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users', indexName: 'fk_stock_movements_actor');
            $table->timestampTz('occurred_at');
            $table->foreignId('reverses_id')->nullable()->constrained('stock_movements', indexName: 'fk_stock_movements_reverses');
            $table->index(['ref_type', 'ref_id'], 'idx_stock_movements_ref');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT ck_stock_movements_qty_delta CHECK (qty_delta <> 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT ck_stock_movements_component CHECK (component IN ('on_hand', 'reserved', 'damaged', 'quarantined', 'in_transit'))");
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT ck_stock_movements_reason CHECK (reason IN ('goods_receipt', 'goods_receipt_reversal', 'sale_ship', 'sale_return', 'adjustment', 'damage', 'quarantine', 'transfer_in', 'transfer_out'))");
        DB::statement('CREATE INDEX idx_stock_movements_variant ON stock_movements (variant_id, occurred_at DESC)');
        DB::statement("CREATE FUNCTION reject_stock_movements_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'stock_movements is append-only' USING ERRCODE = '23514'; END; $$");
        DB::statement('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION reject_stock_movements_mutation()');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        DB::statement('DROP FUNCTION IF EXISTS reject_stock_movements_mutation()');
    }
};
