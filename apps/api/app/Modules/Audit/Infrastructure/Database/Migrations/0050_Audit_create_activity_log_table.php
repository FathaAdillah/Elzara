<?php

// modul: Audit | level: L0 | sprint: 0 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('log_name', 120)->nullable();
            $table->text('description');
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event', 64)->nullable();
            $table->string('causer_type', 120)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->jsonb('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->index(['subject_type', 'subject_id'], 'idx_activity_log_subject');
            $table->index(['causer_type', 'causer_id'], 'idx_activity_log_causer');
            $table->index('log_name', 'idx_activity_log_log_name');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
