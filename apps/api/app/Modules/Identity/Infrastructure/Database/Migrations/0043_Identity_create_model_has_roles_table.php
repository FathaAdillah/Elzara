<?php

// modul: Identity | level: L1 | sprint: 0 | tier: 1 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('roles', indexName: 'fk_model_has_roles_roles')->cascadeOnDelete();
            $table->string('model_type', 120);
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type'], 'pk_model_has_roles');
            $table->index(['model_type', 'model_id'], 'idx_model_has_roles_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_roles');
    }
};
