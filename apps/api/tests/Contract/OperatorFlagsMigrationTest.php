<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OperatorFlagsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
        $schema = 'operator_contract_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$schema}");
        DB::statement("SET LOCAL search_path TO {$schema}, public");
        DB::statement('CREATE TABLE media (id bigint PRIMARY KEY)');
        $this->migration('0042_Identity_create_users_table')->up();
        (require app_path('Modules/Audit/Infrastructure/Database/Migrations/0049_Audit_create_audit_logs_table.php'))->up();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    public function test_baseline_up_down_and_command_are_no_ops(): void
    {
        $before = $this->shape();
        foreach (['0051_Identity_expand_users_operator_flags', '0052_Identity_validate_users_operator_check', '0053_Identity_contract_users_drop_kind'] as $name) {
            $this->migration($name)->up();
        }
        $this->assertSame(0, Artisan::call('identity:backfill-operator-flags'));
        $this->assertStringContainsString('no-op', Artisan::output());
        foreach (['0053_Identity_contract_users_drop_kind', '0052_Identity_validate_users_operator_check', '0051_Identity_expand_users_operator_flags'] as $name) {
            $this->migration($name)->down();
        }
        $this->assertEquals($before, $this->shape());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_legacy_batches_idempotency_contract_and_rollback(): void
    {
        $this->legacy();
        $legacy = $this->shape();
        $rows = [];
        for ($i = 0; $i < 1002; $i++) {
            $rows[] = ['public_id' => (string) Str::ulid(), 'name' => 'Legacy', 'email' => "legacy{$i}@example.test", 'kind' => $i === 0 ? 'customer' : 'staff'];
        }
        DB::table('users')->insert($rows);
        $expand = $this->migration('0051_Identity_expand_users_operator_flags');
        $validate = $this->migration('0052_Identity_validate_users_operator_check');
        $contract = $this->migration('0053_Identity_contract_users_drop_kind');
        $expand->up();
        $this->assertFalse($this->validated());
        DB::table('users')->where('kind', 'staff')->update(['is_operator' => true, 'is_admin' => true]);
        DB::table('users')->where('kind', 'customer')->update(['is_operator' => null, 'is_admin' => null]);
        $this->assertSame(0, Artisan::call('identity:backfill-operator-flags'));
        $this->assertStringContainsString('1002 baris diperbarui', Artisan::output());
        $this->assertSame([1000, 2, 0], DB::table('audit_logs')->orderBy('id')->get()->map(fn ($row) => json_decode($row->after, true)['updated'])->all());
        $this->assertSame(0, Artisan::call('identity:backfill-operator-flags'));
        $this->assertStringContainsString('0 baris diperbarui', Artisan::output());
        $this->assertSame(1001, DB::table('users')->where('is_operator', true)->count());
        $this->assertSame(0, DB::table('users')->where('is_admin', true)->count());
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'backfill.operator_flags')->where('target_type', 'users')->where('target_id', 0)->whereNotNull('request_id')->count());
        $validate->up();
        $this->assertTrue($this->validated());
        $contract->up();
        $this->assertFalse(Schema::hasColumn('users', 'kind'));
        $this->assertContains('users_operator_status', array_column(Schema::getIndexes('users'), 'name'));
        $this->assertNotContains('idx_users_kind_status', array_column(Schema::getIndexes('users'), 'name'));
        $this->assertSame(['ck_users_admin_requires_operator', 'ck_users_status'], array_map(fn ($row) => $row->conname, DB::select("SELECT conname FROM pg_constraint WHERE conrelid = 'users'::regclass AND contype = 'c' ORDER BY conname")));
        $this->assertSame(0, DB::table('information_schema.columns')->whereRaw('table_schema = current_schema()')->where('table_name', 'users')->whereIn('column_name', ['is_operator', 'is_admin'])->where('is_nullable', 'YES')->count());
        $contract->down();
        $this->assertSame(1001, DB::table('users')->where('kind', 'staff')->count());
        $validate->down();
        $this->assertFalse($this->validated());
        $expand->down();
        $this->assertEquals($legacy, $this->shape());
        $expand->up();
        Artisan::call('identity:backfill-operator-flags');
        $validate->up();
        $contract->up();
        $this->assertSame(1001, DB::table('users')->where('is_operator', true)->count());
    }

    public function test_validation_rejects_null_flags(): void
    {
        $this->invalidGate(false);
    }

    public function test_validation_rejects_admin_without_operator(): void
    {
        $this->invalidGate(true);
    }

    public function test_empty_legacy_backfill_is_audited(): void
    {
        $this->legacy();
        $this->migration('0051_Identity_expand_users_operator_flags')->up();
        $this->assertSame(0, Artisan::call('identity:backfill-operator-flags'));
        $this->assertStringContainsString('0 baris diperbarui', Artisan::output());
        $this->assertSame('{"updated": 0}', DB::table('audit_logs')->value('after'));
    }

    private function invalidGate(bool $admin): void
    {
        $this->legacy();
        DB::table('users')->insert(['public_id' => (string) Str::ulid(), 'name' => 'Legacy', 'email' => 'gate@example.test']);
        $this->migration('0051_Identity_expand_users_operator_flags')->up();
        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_admin_requires_operator_v2');
        DB::table('users')->update(['is_operator' => $admin ? false : null, 'is_admin' => $admin]);
        DB::statement('ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator_v2 CHECK (NOT is_admin OR is_operator) NOT VALID');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operator flag validation failed');
        $this->migration('0052_Identity_validate_users_operator_check')->up();
    }

    private function legacy(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_admin_requires_operator');
        DB::statement('DROP INDEX users_operator_status');
        DB::statement("ALTER TABLE users DROP COLUMN is_operator, DROP COLUMN is_admin, ADD COLUMN kind varchar(16) NOT NULL DEFAULT 'customer'");
        DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_kind CHECK (kind IN ('customer', 'staff'))");
        DB::statement('CREATE INDEX idx_users_kind_status ON users (kind, status)');
    }

    private function migration(string $name): Migration
    {
        return require app_path("Modules/Identity/Infrastructure/Database/Migrations/{$name}.php");
    }

    private function validated(): bool
    {
        return DB::selectOne("SELECT convalidated FROM pg_constraint WHERE conrelid = 'users'::regclass AND conname = 'ck_users_admin_requires_operator_v2'")->convalidated;
    }

    private function shape(): array
    {
        return [
            DB::select("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' ORDER BY column_name"),
            DB::select("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'users'::regclass ORDER BY conname"),
            Schema::getIndexes('users'),
        ];
    }
}
