<?php

// modul: PreOrder | level: L2 | sprint: 4 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorder_batches', function (Blueprint $t): void {
            $t->id()->generatedAs()->always();
            $t->string('public_id', 32)->unique('uq_preorder_batches_public_id');
            $t->string('code', 24)->unique('uq_preorder_batches_code');
            $t->string('title', 140);
            $t->string('status', 24)->default('draft');
            $t->timestampTz('order_window_open_at');
            $t->timestampTz('order_window_close_at');
            $t->date('eta_min_date')->nullable();
            $t->date('eta_max_date')->nullable();
            $t->string('deposit_kind', 12)->default('percent');
            $t->bigInteger('deposit_value');
            $t->integer('balance_due_days')->default(7);
            $t->integer('grace_days')->default(2);
            $t->string('forfeit_policy', 20)->default('forfeit');
            $t->smallInteger('max_extensions')->default(1);
            $t->bigInteger('intl_fee_min')->default(0);
            $t->bigInteger('intl_fee_max')->default(0);
            $t->char('currency_code', 3)->default('IDR');
            $t->string('shipping_mode', 24)->default('charged_at_release');
            $t->timestampTz('closed_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users', indexName: 'fk_preorder_batches_creator')->restrictOnDelete();
            $t->timestampsTz();
            $t->index(['status', 'order_window_close_at'], 'idx_preorder_batches_status_window');
        });
        DB::statement("ALTER TABLE preorder_batches ADD COLUMN reminder_offsets integer[] NOT NULL DEFAULT '{7,1}'");
        DB::statement("ALTER TABLE preorder_batches ADD CONSTRAINT ck_preorder_batches_policy CHECK (order_window_close_at > order_window_open_at AND intl_fee_min >= 0 AND intl_fee_max >= intl_fee_min AND deposit_kind IN ('percent','flat') AND deposit_value >= 0 AND (deposit_kind <> 'percent' OR deposit_value <= 10000) AND balance_due_days >= 0 AND grace_days >= 0 AND max_extensions >= 0 AND forfeit_policy IN ('forfeit','voucher','extend_once') AND shipping_mode = 'charged_at_release' AND status IN ('draft','open','closed','received','cancelled_by_supplier','archived'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_batches');
    }
};
