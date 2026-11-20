<?php

declare(strict_types=1);

namespace Tests\Contract;

use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FoundationMigrationTest extends TestCase
{
    private const UUID = 'a8d4bccc-56a7-40ad-a0f1-a4761fc6886f';

    private const TABLES = ['outbox_events', 'inbox_events', 'idempotency_keys', 'jobs', 'job_batches', 'failed_jobs'];

    public function test_foundation_migrations_and_reverse_order_on_postgresql(): void
    {
        DB::beginTransaction();

        try {
            $schema = 'foundation_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET LOCAL search_path TO {$schema}, public");

            $migrations = $this->migrations();
            foreach (array_slice($migrations, 0, 2) as $migration) {
                $migration->up();
            }
            foreach (self::TABLES as $table) {
                $this->assertTrue(Schema::hasTable("{$schema}.{$table}"), "Missing {$table}");
            }

            $this->assertColumnTypes();
            $this->assertIndexes();
            $this->assertConstraints();

            DB::table('idempotency_keys')->insert($this->key(999));
            DB::table('idempotency_keys')->where('actor_id', 999)->delete();

            foreach (array_slice($migrations, 2) as $migration) {
                $migration->up();
            }

            $this->assertRejects('23503', fn () => DB::table('idempotency_keys')->insert($this->key(999)));
            $userId = (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'name' => 'Foundation contract fixture',
                'email' => 'foundation@example.test',
            ]);
            DB::table('idempotency_keys')->insert($this->key($userId));
            $this->assertSame(1, DB::table('idempotency_keys')->where('actor_id', $userId)->count());
            DB::table('users')->where('id', $userId)->delete();
            $this->assertSame(0, DB::table('idempotency_keys')->where('actor_id', $userId)->count());

            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            foreach ([...self::TABLES, 'users', 'media'] as $table) {
                $this->assertFalse(Schema::hasTable("{$schema}.{$table}"), "Rollback left {$table}");
            }
        } finally {
            DB::rollBack();
        }
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        return [
            require app_path('Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php'),
            require database_path('migrations/0002_Platform_create_jobs_table.php'),
            require app_path('Modules/Content/Infrastructure/Database/Migrations/0010_Content_create_media_table.php'),
            require app_path('Modules/Identity/Infrastructure/Database/Migrations/0042_Identity_create_users_table.php'),
            require app_path('Platform/Infrastructure/Database/Migrations/0079_Platform_link_idempotency_keys_to_users.php'),
        ];
    }

    private function assertColumnTypes(): void
    {
        $columns = DB::query()->fromRaw('information_schema.columns')
            ->whereRaw('table_schema = current_schema()')
            ->selectRaw("table_name || '.' || column_name AS path, data_type")
            ->pluck('data_type', 'path');

        foreach ([
            'outbox_events.aggregate_id' => 'bigint',
            'outbox_events.payload' => 'jsonb',
            'outbox_events.dispatched_at' => 'timestamp with time zone',
            'inbox_events.event_id' => 'uuid',
            'inbox_events.payload' => 'jsonb',
            'inbox_events.processed_at' => 'timestamp with time zone',
            'idempotency_keys.actor_id' => 'bigint',
            'idempotency_keys.request_hash' => 'character',
            'idempotency_keys.expires_at' => 'timestamp with time zone',
            'jobs.id' => 'bigint',
            'job_batches.id' => 'character varying',
            'failed_jobs.failed_at' => 'timestamp without time zone',
        ] as $path => $type) {
            $this->assertSame($type, $columns[$path], $path);
        }
    }

    private function assertIndexes(): void
    {
        $indexes = DB::table('pg_indexes')->whereRaw('schemaname = current_schema()')->pluck('indexdef', 'indexname');
        foreach ([
            'idx_outbox_agg' => '(aggregate_type, aggregate_id, occurred_at)',
            'idx_outbox_pending' => 'WHERE (dispatched_at IS NULL)',
            'uq_inbox_once' => '(consumer, event_id)',
            'idx_inbox_status' => '(status, received_at)',
            'uq_inbox_provider_event' => 'WHERE (provider_event_id IS NOT NULL)',
            'uq_idempotency_once' => '(actor_id, operation, idempotency_key)',
            'idx_idempotency_expiry' => '(expires_at)',
            'jobs_queue_index' => '(queue)',
            'failed_jobs_uuid_unique' => '(uuid)',
        ] as $name => $definition) {
            $this->assertStringContainsString($definition, $indexes[$name], $name);
        }
    }

    private function assertConstraints(): void
    {
        $delivery = ['consumer' => 'inventory', 'event_id' => self::UUID, 'provider' => 'fixture', 'provider_event_id' => 'fixture-1'];
        DB::table('inbox_events')->insert($delivery);
        $this->assertRejects('23505', fn () => DB::table('inbox_events')->insert($delivery));
        $this->assertRejects('23505', fn () => DB::table('inbox_events')->insert(array_replace($delivery, [
            'consumer' => 'reporting',
        ])));
        $this->assertRejects('23514', fn () => DB::table('inbox_events')->insert([
            'consumer' => 'reporting',
            'event_id' => self::UUID,
            'status' => 'invalid',
        ]));

        DB::table('idempotency_keys')->insert($this->key(998));
        $this->assertRejects('23505', fn () => DB::table('idempotency_keys')->insert($this->key(998)));
        $this->assertRejects('23514', fn () => DB::table('idempotency_keys')->insert(array_replace($this->key(998), [
            'idempotency_key' => 'other',
            'status' => 'invalid',
        ])));
        DB::table('idempotency_keys')->where('actor_id', 998)->delete();
    }

    private function assertRejects(string $sqlState, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("Expected PostgreSQL SQLSTATE {$sqlState}.");
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }

    /** @return array<string, mixed> */
    private function key(int $actor): array
    {
        return [
            'actor_id' => $actor,
            'operation' => 'foundation-contract',
            'idempotency_key' => 'fixture',
            'request_hash' => str_repeat('0', 64),
            'expires_at' => now()->addMinute(),
        ];
    }
}
