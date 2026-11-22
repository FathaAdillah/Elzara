<?php

// modul: Identity | level: L1 | sprint: 0 | tier: 1 | expand: ya (rilis N) | contract: 0053 (rilis N+1)

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'kind')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_operator')->nullable()->default(false);
            $table->boolean('is_admin')->nullable()->default(false);
            $table->index(['is_operator', 'status'], 'users_operator_status');
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator_v2 CHECK (NOT is_admin OR is_operator) NOT VALID');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'kind')) {
            return;
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_admin_requires_operator_v2');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_operator_status');
            $table->dropColumn(['is_operator', 'is_admin']);
        });
    }
};
