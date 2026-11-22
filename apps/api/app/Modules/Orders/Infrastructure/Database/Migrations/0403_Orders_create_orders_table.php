<?php

// modul: Orders | level: L4 | sprint: 3 | tier: 4 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 32)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('checkout_session_id')->nullable()->unique()->constrained('checkout_sessions')->nullOnDelete();
            $table->string('order_type', 16)->default('ready_stock');
            $table->string('order_status', 24)->default('awaiting_payment');
            $table->string('payment_status', 20)->default('unpaid');
            $table->string('fulfillment_status', 24)->default('reserved');
            $table->string('fulfillment_lane', 20)->default('rts');
            $table->string('procurement_status', 24)->default('not_required');
            $table->bigInteger('subtotal');
            $table->bigInteger('shipping_total');
            $table->bigInteger('total');
            $table->char('currency_code', 3)->default('IDR');
            $table->jsonb('shipping_snapshot');
            $table->text('notes')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'placed_at']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT ck_orders_checkout_amounts CHECK (subtotal >= 0 AND shipping_total >= 0 AND total >= 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_order_status CHECK (order_status IN ('awaiting_payment','paid','processing','shipped','delivered','completed','on_hold','refund_requested','refund_pending','refunded','disputed','expired','cancelled'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT ck_orders_payment_status CHECK (payment_status IN ('unpaid','partially_paid','paid','overdue','refund_pending','refunded','disputed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
