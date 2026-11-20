<?php

// modul: Identity | level: L0 | sprint: 0 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('name', 64);
            $table->string('guard_name', 32)->default('admin');
            $table->string('group_name', 40);
            $table->text('description')->nullable();
            $table->unique(['name', 'guard_name'], 'uq_permissions_name_guard');
            $table->index('group_name', 'idx_permissions_group');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
