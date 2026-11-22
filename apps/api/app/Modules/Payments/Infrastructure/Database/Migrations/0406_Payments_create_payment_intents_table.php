<?php

// modul: Payments | level: L6 | sprint: 3 | tier: 6 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 32)->unique();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('provider', 24);
            $table->string('provider_ref', 120);
            $table->bigInteger('amount');
            $table->char('currency_code', 3)->default('IDR');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at');
            $table->timestampTz('captured_at')->nullable();
            $table->timestampsTz();
            $table->index(['order_id', 'id']);
            $table->unique(['provider', 'provider_ref']);
        });

        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT ck_payment_intents_amount CHECK (amount > 0)');
        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT ck_payment_intents_status CHECK (status IN ('pending','requires_action','authorized','captured','void','expired','failed'))");
        DB::statement("CREATE INDEX idx_payment_intents_expiry ON payment_intents(expires_at, id) WHERE status IN ('pending','requires_action')");
        DB::statement("CREATE UNIQUE INDEX uq_payment_intents_active_invoice ON payment_intents(invoice_id) WHERE status IN ('pending','requires_action','authorized','captured')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
