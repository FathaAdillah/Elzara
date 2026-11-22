<?php

declare(strict_types=1);

namespace Tests\Concurrency\PreOrder;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Concurrency\ConcurrencyTestCase;
use Tests\Support\Concurrency\ParallelRunner;
use Tests\Support\Concurrency\StockQuotaFixture;

final class BatchQuotaTest extends ConcurrencyTestCase
{
    public function test_500_competing_enrollments_accept_exactly_50_without_oversell(): void
    {
        $fixture = new StockQuotaFixture;
        try {
            $schema = $fixture->schema;
            foreach (['preorder_batches', 'preorder_batch_items', 'preorder_enrollments'] as $table) {
                DB::statement("CREATE TABLE {$schema}.{$table} (LIKE public.{$table} INCLUDING ALL)");
            }
            DB::table($schema.'.preorder_batches')->insert([
                'public_id' => 'batch_'.Str::ulid(), 'code' => 'CONCURRENT', 'title' => 'Concurrency', 'status' => 'open',
                'order_window_open_at' => now()->subDay(), 'order_window_close_at' => now()->addDay(), 'deposit_value' => 5000,
            ]);
            DB::table($schema.'.preorder_batch_items')->insert([
                'public_id' => 'bitem_'.Str::ulid(), 'batch_id' => 1, 'variant_id' => 1, 'quota' => 50, 'price' => 100,
            ]);
            $results = (new ParallelRunner)->run($schema, 'preorder', workers: 25, attempts: 20, timeout: 60);
            $this->assertSame(50, array_sum(array_column($results, 'accepted')));
            $this->assertSame(50, DB::table($schema.'.preorder_batch_items')->value('reserved_qty'));
            $this->assertSame(50, DB::table($schema.'.preorder_enrollments')->count());
            foreach ($results as $result) {
                foreach ($result['observations'] as $reserved) {
                    $this->assertLessThanOrEqual(50, $reserved);
                }
            }
        } finally {
            $fixture->close();
        }
    }
}
