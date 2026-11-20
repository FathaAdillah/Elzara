<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ActivityLogMigrationTest extends TestCase
{
    public function test_activitylog_v5_writes_changes_and_down_only_removes_the_new_column(): void
    {
        DB::beginTransaction();

        try {
            $schema = 'activity_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET LOCAL search_path TO {$schema}");

            $base = require app_path('Modules/Audit/Infrastructure/Database/Migrations/0050_Audit_create_activity_log_table.php');
            $delta = require app_path('Modules/Audit/Infrastructure/Database/Migrations/0054_Audit_add_attribute_changes_to_activity_log.php');
            $base->up();
            $legacyId = DB::table('activity_log')->insertGetId([
                'log_name' => 'fixture',
                'description' => 'Legacy fixture entry',
                'properties' => '{"legacy":true}',
            ]);
            $legacy = (array) DB::table('activity_log')->find($legacyId);
            $legacyColumns = Schema::getColumnListing('activity_log');
            $this->assertFalse(Schema::hasColumn('activity_log', 'attribute_changes'));

            $delta->up();
            $this->assertSame('jsonb', DB::query()->fromRaw('information_schema.columns')
                ->whereRaw('table_schema = current_schema()')
                ->where('table_name', 'activity_log')
                ->where('column_name', 'attribute_changes')
                ->value('data_type'));
            $this->assertSame(
                $legacy + ['attribute_changes' => null],
                (array) DB::table('activity_log')->find($legacyId),
            );

            $changes = ['attributes' => ['status' => 'paid'], 'old' => ['status' => 'awaiting_payment']];
            $activity = activity('fixture')
                ->event('updated')
                ->withProperties(['request_id' => 'fixture-request'])
                ->withChanges($changes)
                ->log('Updated fixture entry');
            $this->assertNotNull($activity);
            $new = DB::table('activity_log')->find($activity->getKey());
            $this->assertSame('updated', $new->event);
            $this->assertEquals($changes, json_decode($new->attribute_changes, true, 8, JSON_THROW_ON_ERROR));
            $this->assertSame(['request_id' => 'fixture-request'], json_decode($new->properties, true, 8, JSON_THROW_ON_ERROR));

            $delta->down();
            $this->assertSame($legacyColumns, Schema::getColumnListing('activity_log'));
            $this->assertSame($legacy, (array) DB::table('activity_log')->find($legacyId));
            $this->assertSame('Updated fixture entry', DB::table('activity_log')->where('id', $activity->getKey())->value('description'));
            $this->assertSame(2, DB::table('activity_log')->count());
        } finally {
            DB::rollBack();
        }
    }
}
