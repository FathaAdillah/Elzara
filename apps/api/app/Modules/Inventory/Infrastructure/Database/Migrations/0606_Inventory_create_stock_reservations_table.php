<?php

// modul: Inventory | level: L6 | sprint: 3 | tier: 6 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('order_id')->constrained('orders', indexName: 'fk_stock_reservations_order')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items', indexName: 'fk_stock_reservations_order_item')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants', indexName: 'fk_stock_reservations_variant')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses', indexName: 'fk_stock_reservations_warehouse')->restrictOnDelete();
            $table->integer('qty');
            $table->string('status', 16)->default('held');
            $table->timestampTz('expires_at');
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['order_id', 'variant_id', 'warehouse_id', 'id'], 'idx_stock_reservations_order_slot');
        });
        DB::statement('ALTER TABLE stock_reservations ADD CONSTRAINT ck_stock_reservations_qty CHECK (qty > 0)');
        DB::statement("ALTER TABLE stock_reservations ADD CONSTRAINT ck_stock_reservations_status CHECK (status IN ('held','committed','released','expired'))");
        DB::statement("CREATE UNIQUE INDEX uq_stock_reservations_once ON stock_reservations(order_item_id, warehouse_id) WHERE status IN ('held','committed')");
        DB::statement("CREATE INDEX idx_stock_reservations_expiry ON stock_reservations(expires_at, id) WHERE status = 'held'");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
