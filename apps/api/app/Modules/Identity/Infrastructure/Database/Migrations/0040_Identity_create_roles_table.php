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
        Schema::create('roles', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('name', 48);
            $table->string('guard_name', 32)->default('admin');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unique(['name', 'guard_name'], 'uq_roles_name_guard');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
