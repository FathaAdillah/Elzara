<?php

// modul: Cart | level: L2 | sprint: 3 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $t): void {
            $t->id()->generatedAs()->always();
            $t->string('token', 32)->nullable()->unique();
            $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->string('status', 16)->default('active');
            $t->char('currency_code', 3)->default('IDR');
            $t->timestampTz('expires_at');
            $t->bigInteger('converted_order_id')->nullable();
            $t->timestampsTz();
        });
        DB::statement('ALTER TABLE carts ADD CONSTRAINT ck_carts_owner CHECK (user_id IS NOT NULL OR token IS NOT NULL)');
        DB::statement("ALTER TABLE carts ADD CONSTRAINT ck_carts_status CHECK (status IN ('active','converted','abandoned','expired'))");
        DB::statement("CREATE UNIQUE INDEX uq_carts_active_user ON carts(user_id) WHERE status='active'");
        DB::statement("CREATE INDEX idx_carts_expiry ON carts(expires_at) WHERE status='active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
