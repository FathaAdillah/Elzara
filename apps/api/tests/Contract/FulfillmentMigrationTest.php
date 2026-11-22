<?php

declare(strict_types=1);

namespace Tests\Contract;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class FulfillmentMigrationTest extends TestCase
{
    public function test_schema_constraints_indexes_and_rollback(): void
    {
        DB::beginTransaction();
        try {
            $schema = 'fulfillment_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET LOCAL search_path TO {$schema}, public");
            DB::statement('CREATE TABLE users (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            $files = glob(app_path('Modules/Fulfillment/Infrastructure/Database/Migrations/*.php'));
            $this->assertCount(3, $files);
            foreach ($files as $file) {
                (require $file)->up();
            }
            $user = DB::table('users')->insertGetId([]);
            $service = ['courier_code' => 'jne', 'service_code' => 'reg', 'name' => 'Regular'];
            DB::table('courier_services')->insert($service);
            $this->rejects('23505', fn () => DB::table('courier_services')->insert($service));
            $rule = ['courier_code' => 'jne', 'service_code' => 'reg', 'package_tier' => 'buku', 'weight_from_g' => 0, 'weight_to_g' => 1000, 'base_amount' => 10000, 'effective_from' => '2026-01-01', 'created_by' => $user];
            DB::table('shipping_tariff_rules')->insert($rule);
            $this->rejects('23514', fn () => DB::table('shipping_tariff_rules')->insert(array_replace($rule, ['weight_to_g' => 0])));
            $this->rejects('23514', fn () => DB::table('shipping_tariff_rules')->insert(array_replace($rule, ['effective_to' => '2025-12-31'])));
            $this->rejects('23503', fn () => DB::table('users')->delete());
            $cache = ['cache_key' => 'ID|dest|500|buku|*', 'amount' => 10000, 'provider' => 'manual', 'rates' => '[]', 'fetched_at' => now(), 'expires_at' => now()->addMinutes(30)];
            DB::table('shipping_rates_cache')->insert($cache);
            $this->rejects('23505', fn () => DB::table('shipping_rates_cache')->insert($cache));
            foreach (['courier_services', 'shipping_tariff_rules', 'shipping_rates_cache'] as $table) {
                $columns = collect(Schema::getColumns($table))->keyBy('name');
                $this->assertSame('int8', $columns['id']['type_name']);
                $this->assertSame('timestamptz', $columns['created_at']['type_name']);
            }
            $columns = collect(Schema::getColumns('shipping_rates_cache'))->keyBy('name');
            $this->assertSame('int8', $columns['amount']['type_name']);
            $this->assertSame('jsonb', $columns['rates']['type_name']);
            $this->assertSame('timestamptz', $columns['expires_at']['type_name']);
            $this->assertTrue(collect(Schema::getIndexes('shipping_rates_cache'))->keyBy('name')->has('idx_rates_expiry'));
            $this->assertSame(['origin_country', 'dest_province', 'courier_code', 'service_code', 'weight_from_g'], collect(Schema::getIndexes('shipping_tariff_rules'))->keyBy('name')['idx_tariff_lookup']['columns']);
            foreach (array_reverse($files) as $file) {
                (require $file)->down();
            }
            $this->assertFalse(Schema::hasTable($schema.'.courier_services'));
            $this->assertFalse(Schema::hasTable($schema.'.shipping_tariff_rules'));
            $this->assertFalse(Schema::hasTable($schema.'.shipping_rates_cache'));
        } finally {
            DB::rollBack();
        }
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
