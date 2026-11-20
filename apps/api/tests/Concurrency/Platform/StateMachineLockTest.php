<?php

declare(strict_types=1);

namespace Tests\Concurrency\Platform;

use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\InvalidTransition;
use App\Platform\StateMachine\StateMachineRunner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StateMachineLockTest extends TestCase
{
    public function test_same_row_waits_for_a_competing_writer_and_only_transitions_once(): void
    {
        $schema = 'transition_lock_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$schema}");

        try {
            $table = "{$schema}.transition_fixture";
            DB::statement("CREATE TABLE {$table} (
                id bigint PRIMARY KEY,
                order_status varchar NOT NULL,
                updated_at timestamp NOT NULL
            )");
            DB::table($table)->insert(['id' => 1, 'order_status' => 'awaiting_payment', 'updated_at' => now()]);

            config(['database.connections.transition_contender' => config('database.connections.pgsql')]);
            $contender = DB::connection('transition_contender');
            $contender->statement("SET lock_timeout TO '200ms'");
            $runner = new StateMachineRunner('transition_contender');

            DB::beginTransaction();
            try {
                DB::table($table)->where('id', 1)->update(['order_status' => 'cancelled']);

                try {
                    $runner->transition($table, 1, Axis::Order, 'paid', 'awaiting_payment');
                    $this->fail('The contender must wait for the row lock.');
                } catch (QueryException $failure) {
                    $this->assertSame('55P03', $failure->errorInfo[0]);
                }
            } finally {
                DB::rollBack();
            }

            $this->assertSame('awaiting_payment', DB::table($table)->where('id', 1)->value('order_status'));
            $this->assertSame('paid', $runner->transition($table, 1, Axis::Order, 'paid', 'awaiting_payment'));
            $after = (array) DB::table($table)->find(1);
            $this->assertSame('paid', $after['order_status']);

            try {
                $runner->transition($table, 1, Axis::Order, 'paid', 'awaiting_payment');
                $this->fail('A replay with stale source status must be rejected.');
            } catch (InvalidTransition $failure) {
                $this->assertSame('paid', $failure->from);
            }

            $this->assertSame($after, (array) DB::table($table)->find(1));
        } finally {
            DB::purge('transition_contender');
            DB::statement("DROP SCHEMA {$schema} CASCADE");
        }
    }
}
