<?php

// modul: Cart | level: L3 | sprint: 3 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_sessions', function (Blueprint $t): void {
            $t->id()->generatedAs()->always();
            $t->string('code', 32)->unique();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('cart_id')->nullable()->constrained('carts')->nullOnDelete();
            $t->string('status', 16)->default('open');
            $t->char('currency_code', 3)->default('IDR');
            $t->decimal('exchange_rate_snapshot', 14, 6)->default(1);
            $t->bigInteger('subtotal')->default(0);
            $t->bigInteger('shipping_estimate')->default(0);
            $t->bigInteger('tax_estimate')->default(0);
            $t->jsonb('snapshot')->default('{}');
            $t->timestampTz('expires_at');
            $t->bigInteger('converted_order_id')->nullable();
            $t->timestampsTz();
        });
        DB::statement("ALTER TABLE checkout_sessions ADD CONSTRAINT ck_checkout_status CHECK (status IN ('open','locked','converted','expired'))");
        DB::statement('ALTER TABLE checkout_sessions ADD CONSTRAINT ck_checkout_exchange CHECK (exchange_rate_snapshot>0)');
        DB::statement("CREATE INDEX idx_checkout_sessions_expiry ON checkout_sessions(expires_at) WHERE status IN ('open','locked')");
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
    }
};
