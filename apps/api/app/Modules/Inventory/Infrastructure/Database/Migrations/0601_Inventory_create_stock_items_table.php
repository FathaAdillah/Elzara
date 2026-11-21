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
        Schema::create('stock_items', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('warehouse_id')->constrained('warehouses', indexName: 'fk_stock_items_warehouse')->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants', indexName: 'fk_stock_items_variant')->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('damaged')->default(0);
            $table->integer('quarantined')->default(0);
            $table->integer('in_transit')->default(0);
            $table->integer('available_to_sell')->storedAs('on_hand - reserved - (damaged + quarantined)');
            $table->integer('min_stock')->default(0);
            $table->unique(['warehouse_id', 'variant_id'], 'uq_stock_items_slot');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_on_hand CHECK (on_hand >= 0)');
        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_reserved CHECK (reserved >= 0)');
        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_damaged CHECK (damaged >= 0)');
        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_quarantined CHECK (quarantined >= 0)');
        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_in_transit CHECK (in_transit >= 0)');
        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_commitment CHECK (reserved + damaged + quarantined <= on_hand + in_transit)');
        DB::statement('ALTER TABLE stock_items ADD CONSTRAINT ck_stock_items_available_to_sell CHECK (available_to_sell = on_hand - reserved - (damaged + quarantined))');
        DB::statement('CREATE INDEX idx_stock_items_warehouse ON stock_items (warehouse_id) WHERE (on_hand - reserved) < min_stock');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
