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
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('permissions', indexName: 'fk_role_has_permissions_permissions')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles', indexName: 'fk_role_has_permissions_roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id'], 'pk_role_has_permissions');
            $table->index('role_id', 'idx_role_has_permissions_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
    }
};
