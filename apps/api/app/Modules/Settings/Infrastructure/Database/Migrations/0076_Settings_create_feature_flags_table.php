<?php

// modul: Settings | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 64)->unique('uq_feature_flags_code');
            $table->string('description', 240);
            $table->boolean('default_enabled')->default(false);
            $table->boolean('requires_restart')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'fk_feature_flags_users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
