<?php

// modul: Billing | level: L5 | sprint: 3 | tier: 5 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 32)->unique();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->char('currency_code', 3)->default('IDR');
            $table->string('status', 16)->default('open');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('issued_at')->useCurrent();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();
            $table->index(['order_id', 'type']);
        });

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT ck_invoices_amount CHECK (amount >= 0)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_type CHECK (type IN ('deposit','balance','intl_fees','domestic_ship','extra_ship','adjustment'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_status CHECK (status IN ('open','paid','overdue','void','refunded'))");
        DB::statement("CREATE UNIQUE INDEX uq_invoices_one_balance ON invoices(order_id) WHERE type = 'balance'");
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
