<?php

declare(strict_types=1);

namespace Tests\Schema;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_server_is_postgresql_16(): void
    {
        $this->assertStringContainsString('PostgreSQL 16.', DB::selectOne('SELECT version() AS version')->version);
    }

    public function test_admin_requires_operator(): void
    {
        $this->rejects('23514', fn () => DB::table('users')->insert($this->user() + ['is_admin' => true, 'is_operator' => false]), 'ck_users_admin_requires_operator');
    }

    public function test_both_flags_default_to_false(): void
    {
        $id = DB::table('users')->insertGetId($this->user());
        $user = DB::table('users')->find($id);
        $this->assertFalse($user->is_operator);
        $this->assertFalse($user->is_admin);
    }

    public function test_operator_index_replaces_legacy_kind_index(): void
    {
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->where('tablename', 'users')->pluck('indexdef', 'indexname');
        $this->assertArrayHasKey('users_operator_status', $indexes->all());
        $this->assertStringContainsString('(is_operator, status)', $indexes['users_operator_status']);
        $this->assertArrayNotHasKey('idx_users_kind_status', $indexes->all());
        $this->assertArrayNotHasKey('users_kind_status', $indexes->all());
    }

    public function test_legacy_kind_column_is_absent(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'kind'));
    }

    public function test_roles_and_permissions_are_unique_per_name_and_default_to_admin_guard(): void
    {
        foreach (['roles', 'permissions'] as $table) {
            $row = ['name' => 'schema.'.Str::lower(Str::random(12))];
            if ($table === 'permissions') {
                $row['group_name'] = 'Identity';
            }
            $id = DB::table($table)->insertGetId($row);
            $this->assertSame('admin', DB::table($table)->where('id', $id)->value('guard_name'));
            $this->rejects('23505', fn () => DB::table($table)->insert($row), 'uq_'.$table.'_name_guard');
            $this->assertTrue(DB::table($table)->insert($row + ['guard_name' => 'web']));
        }
    }

    public function test_provider_identity_is_unique_across_users(): void
    {
        $first = DB::table('users')->insertGetId($this->user());
        $second = DB::table('users')->insertGetId($this->user());
        $identity = ['provider' => 'google', 'provider_user_id' => (string) Str::ulid()];
        DB::table('user_identities')->insert($identity + ['user_id' => $first]);
        $this->rejects('23505', fn () => DB::table('user_identities')->insert($identity + ['user_id' => $second]), 'uq_user_identities_provider');
        $this->assertTrue(DB::table('user_identities')->insert(array_replace($identity, ['provider' => 'x', 'user_id' => $second])));
    }

    public function test_recovery_hashes_are_a_text_array(): void
    {
        $column = DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'staff_2fa_secrets')->where('column_name', 'recovery_hashes')->first();
        $this->assertNotNull($column);
        $this->assertSame('ARRAY', $column->data_type);
        $this->assertSame('_text', $column->udt_name);
    }

    public function test_audit_request_id_is_required_and_reason_cannot_be_empty(): void
    {
        $row = ['action' => 'schema.test', 'target_type' => 'users', 'target_id' => 1, 'reason' => 'Schema contract'];
        $this->rejects('23502', fn () => DB::table('audit_logs')->insert($row));
        $row['request_id'] = (string) Str::ulid();
        foreach (['', '   '] as $reason) {
            $this->rejects('23514', fn () => DB::table('audit_logs')->insert(array_replace($row, ['reason' => $reason])), 'ck_audit_logs_reason');
        }
        $this->assertTrue(DB::table('audit_logs')->insert($row));
    }

    /** @return array<string, string> */
    private function user(): array
    {
        $id = (string) Str::ulid();

        return ['public_id' => $id, 'name' => 'Schema fixture', 'email' => strtolower($id).'@example.test'];
    }

    private function rejects(string $state, Closure $write, ?string $constraint = null): void
    {
        try {
            DB::transaction($write);
            $this->fail('Expected PostgreSQL SQLSTATE '.$state);
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }
    }
}
