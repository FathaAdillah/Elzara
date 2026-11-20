<?php

// modul: Identity | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_2fa_secrets', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->unique('uq_staff_2fa_secrets_user')->constrained('users', indexName: 'fk_staff_2fa_secrets_users')->cascadeOnDelete();
            $table->binary('secret_encrypted');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE staff_2fa_secrets ADD COLUMN recovery_hashes text[] NOT NULL DEFAULT '{}'::text[]");
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_2fa_secrets');
    }
};
