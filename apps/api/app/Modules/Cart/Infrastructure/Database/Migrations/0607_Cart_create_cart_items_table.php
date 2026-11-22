<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $t): void {
            $t->id()->generatedAs()->always();
            $t->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $t->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $t->bigInteger('batch_offer_id')->nullable();
            $t->integer('qty');
            $t->bigInteger('unit_price_snapshot');
            $t->timestampTz('added_at')->useCurrent();
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT ck_cart_qty CHECK (qty>0)');
        DB::statement('CREATE UNIQUE INDEX uq_cart_item_line ON cart_items(cart_id,variant_id,COALESCE(batch_offer_id,0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
