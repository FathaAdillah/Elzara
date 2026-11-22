<?php

// modul: Identity | level: L1 | sprint: 0 | tier: 1 | expand: tidak | contract: 0053 (rilis N+1)

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'kind')) {
            return;
        }

        if (DB::table('users')->whereNull('is_operator')->orWhereNull('is_admin')
            ->orWhereRaw('(is_admin AND NOT is_operator)')->exists()) {
            throw new RuntimeException('Operator flag validation failed: NULL flags or admin without operator.');
        }

        DB::statement('ALTER TABLE users VALIDATE CONSTRAINT ck_users_admin_requires_operator_v2');
        DB::statement('ALTER TABLE users ALTER COLUMN is_operator SET NOT NULL, ALTER COLUMN is_admin SET NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'kind')) {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN is_operator DROP NOT NULL, ALTER COLUMN is_admin DROP NOT NULL');
        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_admin_requires_operator_v2');
        DB::statement('ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator_v2 CHECK (NOT is_admin OR is_operator) NOT VALID');
    }
};
