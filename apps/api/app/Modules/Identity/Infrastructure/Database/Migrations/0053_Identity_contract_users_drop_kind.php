<?php

// modul: Identity | level: L1 | sprint: 0 | tier: 1 | expand: tidak | contract: ya (rilis N+1, terpisah dari 0051)

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

        DB::statement('ALTER TABLE users ALTER COLUMN is_operator SET NOT NULL, ALTER COLUMN is_admin SET NOT NULL');
        DB::statement('ALTER TABLE users RENAME CONSTRAINT ck_users_admin_requires_operator_v2 TO ck_users_admin_requires_operator');
        DB::statement("COMMENT ON CONSTRAINT ck_users_admin_requires_operator ON users IS 'Identity 0053 legacy contract'");
        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_kind');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('idx_users_kind_status');
            $table->dropColumn('kind');
        });
    }

    public function down(): void
    {
        $origin = DB::scalar("SELECT obj_description(oid, 'pg_constraint') FROM pg_constraint WHERE conrelid = 'users'::regclass AND conname = 'ck_users_admin_requires_operator'");
        if ($origin !== 'Identity 0053 legacy contract') {
            return;
        }

        DB::statement('ALTER TABLE users ALTER COLUMN is_operator DROP NOT NULL, ALTER COLUMN is_admin DROP NOT NULL');
        Schema::table('users', function (Blueprint $table): void {
            $table->string('kind', 16)->default('customer');
            $table->index(['kind', 'status'], 'idx_users_kind_status');
        });
        DB::update("UPDATE users SET kind = CASE WHEN is_operator THEN 'staff' ELSE 'customer' END");
        DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_kind CHECK (kind IN ('customer', 'staff'))");
        DB::statement('ALTER TABLE users RENAME CONSTRAINT ck_users_admin_requires_operator TO ck_users_admin_requires_operator_v2');
        DB::statement('COMMENT ON CONSTRAINT ck_users_admin_requires_operator_v2 ON users IS NULL');
    }
};
