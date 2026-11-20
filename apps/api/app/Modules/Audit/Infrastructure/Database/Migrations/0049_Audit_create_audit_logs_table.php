<?php

// modul: Audit | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('actor_id')->nullable()->constrained('users', indexName: 'fk_audit_logs_actor')->nullOnDelete();
            $table->string('action', 64);
            $table->string('target_type', 64);
            $table->unsignedBigInteger('target_id');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('reason', 320);
            $table->string('request_id', 26);
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT ck_audit_logs_reason CHECK (length(trim(reason)) > 0)');
        DB::statement('CREATE INDEX idx_audit_target ON audit_logs (target_type, target_id, occurred_at DESC)');
        DB::statement('CREATE INDEX idx_audit_actor_time ON audit_logs (actor_id, occurred_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
