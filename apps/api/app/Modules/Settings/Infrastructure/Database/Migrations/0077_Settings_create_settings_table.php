<?php

// modul: Settings | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('key', 120)->unique('uq_settings_key');
            $table->string('group_name', 48);
            $table->string('value_type', 12);
            $table->jsonb('value');
            $table->boolean('is_public')->default(false);
            $table->string('description', 240)->nullable();
            $table->jsonb('default_value');
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'fk_settings_users');
            $table->index('group_name', 'idx_settings_group');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE settings ADD CONSTRAINT ck_settings_value_type CHECK (value_type IN ('string', 'int', 'bool', 'decimal', 'json'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
