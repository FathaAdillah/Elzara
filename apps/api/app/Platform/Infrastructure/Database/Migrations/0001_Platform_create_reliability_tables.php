<?php

// modul: Platform (lintas modul — pemilik tulis trio reliability, Doc 01 §3.1) | level: L0 | sprint: 0 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $t): void {
            $t->id();
            $t->string('event_name', 80);
            $t->string('aggregate_type', 40);
            $t->bigInteger('aggregate_id');
            $t->jsonb('payload');
            $t->timestampTz('occurred_at')->useCurrent();
            $t->timestampTz('dispatched_at')->nullable();
            $t->smallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->string('locked_by', 64)->nullable();
            $t->timestampTz('locked_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();

            $t->index(['aggregate_type', 'aggregate_id', 'occurred_at'], 'idx_outbox_agg');
        });

        DB::statement('CREATE INDEX idx_outbox_pending ON outbox_events (occurred_at) WHERE dispatched_at IS NULL');

        Schema::create('inbox_events', function (Blueprint $t): void {
            $t->id();
            $t->string('consumer', 64);
            $t->uuid('event_id');
            $t->string('provider', 24)->nullable();
            $t->string('provider_event_id', 120)->nullable();
            $t->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $t->timestampTz('received_at')->useCurrent();
            $t->string('status', 16)->default('pending');
            $t->timestampTz('processed_at')->nullable();
            $t->text('error')->nullable();

            $t->unique(['consumer', 'event_id'], 'uq_inbox_once');
            $t->index(['status', 'received_at'], 'idx_inbox_status');
        });

        DB::statement('CREATE UNIQUE INDEX uq_inbox_provider_event ON inbox_events (provider, provider_event_id)'
            . " WHERE provider_event_id IS NOT NULL");   // dedup webhook Sprint 0; webhook_receipts (Payments) baru Sprint 2 (Doc 03 0.7)

        DB::statement("ALTER TABLE inbox_events ADD CONSTRAINT ck_inbox_events_status"
            . " CHECK (status IN ('pending','processed','failed','ignored'))");

        Schema::create('idempotency_keys', function (Blueprint $t): void {
            $t->id();
            // FK users(id) ON DELETE CASCADE menyusul (Doc 10 C3); id dan uang selalu bigint (S-1)
            $t->bigInteger('actor_id');
            $t->string('operation', 80);
            $t->string('idempotency_key', 120);
            $t->char('request_hash', 64);
            $t->string('status', 16)->default('in_progress');
            $t->string('response_ref', 200)->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();

            $t->unique(['actor_id', 'operation', 'idempotency_key'], 'uq_idempotency_once');
            $t->index('expires_at', 'idx_idempotency_expiry');
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT ck_idempotency_keys_status"
            . " CHECK (status IN ('in_progress','succeeded','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('inbox_events');
        Schema::dropIfExists('outbox_events');
    }
};
