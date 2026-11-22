<?php

// modul: Orders | level: L5 | sprint: 3 | tier: 5 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('fulfillment_mode', 16)->default('rts');
            $table->integer('qty');
            $table->bigInteger('unit_price');
            $table->char('currency_code', 3)->default('IDR');
            $table->string('product_name', 240);
            $table->string('sku', 120)->nullable();
            $table->jsonb('price_snapshot')->default('{}');
            $table->timestampsTz();
            $table->index('order_id');
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT ck_order_items_amounts CHECK (qty > 0 AND unit_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
