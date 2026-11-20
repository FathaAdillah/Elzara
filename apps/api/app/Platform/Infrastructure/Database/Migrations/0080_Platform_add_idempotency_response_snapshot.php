<?php

// modul: Platform | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->jsonb('response_headers')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
                ADD CONSTRAINT ck_idempotency_response_snapshot CHECK (
                    (response_status IS NULL AND response_body IS NULL AND response_headers IS NULL)
                    OR (
                        response_status IS NOT NULL
                        AND response_status BETWEEN 200 AND 499
                        AND response_body IS NOT NULL
                        AND response_headers IS NOT NULL
                    )
                ),
                ADD CONSTRAINT ck_idempotency_response_headers CHECK (
                    CASE
                        WHEN response_headers IS NULL THEN TRUE
                        WHEN jsonb_typeof(response_headers) = 'object' THEN
                            response_headers - ARRAY['Content-Type', 'Location', 'X-Request-Id'] = '{}'::jsonb
                            AND coalesce(jsonb_typeof(response_headers -> 'Content-Type'), 'string') = 'string'
                            AND coalesce(jsonb_typeof(response_headers -> 'Location'), 'string') = 'string'
                            AND coalesce(jsonb_typeof(response_headers -> 'X-Request-Id'), 'string') = 'string'
                        ELSE FALSE
                    END
                )
            SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys
                DROP CONSTRAINT ck_idempotency_response_snapshot,
                DROP CONSTRAINT ck_idempotency_response_headers
            SQL);

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropColumn(['response_status', 'response_body', 'response_headers']);
        });
    }
};
