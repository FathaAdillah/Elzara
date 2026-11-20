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
        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_user_sessions_users')->cascadeOnDelete();
            $table->string('session_hash', 64)->unique('uq_user_sessions_hash');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device_label', 80)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('CREATE INDEX idx_user_sessions_user ON user_sessions (user_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
