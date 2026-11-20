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
        Schema::create('role_scopes', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_role_scopes_users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles', indexName: 'fk_role_scopes_roles')->cascadeOnDelete();
            $table->string('scope_type', 24)->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->index('user_id', 'idx_role_scopes_user');
        });

        DB::statement("CREATE UNIQUE INDEX uq_role_scopes_identity ON role_scopes (user_id, role_id, COALESCE(scope_type, ''), COALESCE(scope_id, 0))");
        DB::statement("ALTER TABLE role_scopes ADD CONSTRAINT ck_role_scopes_type CHECK (scope_type IN ('batch', 'category', 'warehouse'))");
        DB::statement('ALTER TABLE role_scopes ADD CONSTRAINT ck_role_scopes_pair CHECK ((scope_type IS NULL AND scope_id IS NULL) OR (scope_type IS NOT NULL AND scope_id IS NOT NULL AND scope_id > 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_scopes');
    }
};
