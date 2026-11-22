<?php

// modul: PreOrder | level: L6 | sprint: 4 | tier: 6 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorder_enrollments', function (Blueprint $t): void {
            $t->id()->generatedAs()->always();
            $t->foreignId('batch_id')->constrained('preorder_batches', indexName: 'fk_preorder_enrollments_batch')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders', indexName: 'fk_preorder_enrollments_order')->cascadeOnDelete();
            $t->foreignId('order_item_id')->constrained('order_items', indexName: 'fk_preorder_enrollments_order_item')->cascadeOnDelete();
            $t->foreignId('batch_item_id')->constrained('preorder_batch_items', indexName: 'fk_preorder_enrollments_batch_item')->restrictOnDelete();
            $t->foreignId('user_id')->constrained('users', indexName: 'fk_preorder_enrollments_user')->restrictOnDelete();
            $t->integer('qty');
            $t->string('state', 24)->default('reserved');
            $t->timestampTz('slot_reserved_at');
            $t->timestampTz('slot_expires_at');
            $t->integer('received_qty')->default(0);
            $t->bigInteger('deposit_amount');
            $t->timestampsTz();
            $t->unique(['order_item_id', 'batch_item_id'], 'uq_preorder_enrollments_one_per_item');
            $t->index(['batch_id', 'state'], 'idx_preorder_enrollments_batch_state');
            $t->index(['batch_item_id', 'user_id'], 'idx_preorder_enrollments_account');
        });
        DB::statement("ALTER TABLE preorder_enrollments ADD CONSTRAINT ck_preorder_enrollments_state CHECK (qty > 0 AND received_qty >= 0 AND received_qty <= qty AND deposit_amount >= 0 AND state IN ('reserved','deposit_paid','awaiting_balance','on_hold','fully_paid','received','shipped','released','expired','cancelled'))");
        DB::statement("CREATE INDEX idx_preorder_enrollments_expiry ON preorder_enrollments (slot_expires_at) WHERE state = 'reserved'");
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_enrollments');
    }
};
