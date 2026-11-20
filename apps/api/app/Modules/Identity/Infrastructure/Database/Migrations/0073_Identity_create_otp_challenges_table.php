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
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_otp_challenges_users')->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('code_hash');
            $table->string('purpose', 24);
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->index(['user_id', 'purpose', 'expires_at'], 'idx_otp_active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT ck_otp_challenges_channel CHECK (channel IN ('email', 'whatsapp', 'sms'))");
        DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT ck_otp_challenges_purpose CHECK (purpose IN ('login', 'reset_password', 'verify_phone', 'verify_email'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
