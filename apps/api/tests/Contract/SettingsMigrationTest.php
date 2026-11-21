<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Settings\Contracts\SettingValueQuery;
use App\Modules\Settings\Infrastructure\Models\Setting;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SettingsMigrationTest extends TestCase
{
    public function test_settings_schema_constraints_query_and_rollback(): void
    {
        DB::beginTransaction();
        try {
            $schema = 'settings_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET LOCAL search_path TO {$schema}, public");
            DB::statement('CREATE TABLE users (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            $migration = require app_path('Modules/Settings/Infrastructure/Database/Migrations/0077_Settings_create_settings_table.php');
            $migration->up();
            $columns = collect(Schema::getColumns('settings'))->keyBy('name');
            foreach (['id' => 'int8', 'key' => 'varchar', 'group_name' => 'varchar', 'value_type' => 'varchar', 'value' => 'jsonb', 'default_value' => 'jsonb', 'is_public' => 'bool', 'updated_by' => 'int8', 'created_at' => 'timestamptz', 'updated_at' => 'timestamptz'] as $name => $type) {
                $this->assertSame($type, $columns[$name]['type_name']);
            }
            $this->assertTrue($columns['updated_by']['nullable']);
            $this->assertTrue($columns['description']['nullable']);
            $indexes = collect(Schema::getIndexes('settings'))->keyBy('name');
            $this->assertTrue($indexes['uq_settings_key']['unique']);
            $this->assertSame(['group_name'], $indexes['idx_settings_group']['columns']);
            $row = ['key' => 'test', 'group_name' => 'system', 'value_type' => 'json', 'value' => '{"v":null}', 'default_value' => '{"v":42}'];
            DB::table('settings')->insert($row);
            $this->assertFalse((bool) DB::table('settings')->value('is_public'));
            $this->assertNull(app(SettingValueQuery::class)->value('test'));
            Setting::query()->where('key', 'test')->update(['value' => []]);
            $this->assertSame(42, app(SettingValueQuery::class)->value('test'));
            foreach ([false, 0, '', ['nested' => true]] as $value) {
                Setting::query()->where('key', 'test')->update(['value' => ['v' => $value]]);
                $this->assertSame($value, app(SettingValueQuery::class)->value('test'));
            }
            $this->rejects('23505', fn () => DB::table('settings')->insert($row));
            $this->rejects('23514', fn () => DB::table('settings')->insert(array_replace($row, ['key' => 'invalid', 'value_type' => 'float'])));
            $this->rejects('23503', fn () => DB::table('settings')->insert(array_replace($row, ['key' => 'orphan', 'updated_by' => 999])));
            $this->rejects('23502', fn () => DB::table('settings')->insert(array_replace($row, ['key' => 'null', 'value' => null])));
            $user = DB::table('users')->insertGetId([]);
            DB::table('settings')->where('key', 'test')->update(['updated_by' => $user]);
            $this->assertSame($user, DB::table('settings')->value('updated_by'));
            $migration->down();
            $this->assertFalse(Schema::hasTable("{$schema}.settings"));
        } finally {
            DB::rollBack();
        }
    }

    public function test_missing_setting_throws_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(SettingValueQuery::class)->value('absent');
    }

    private function rejects(string $state, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("Expected {$state}");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
