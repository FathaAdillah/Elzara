<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Outbox\DispatchDomainEvent;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\InboxConsumer;
use App\Platform\Outbox\OutboxDispatcher;
use App\Platform\Outbox\OutboxWriter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

final class OutboxDispatchTest extends TestCase
{
    private ?string $fixtureSchema = null;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = 'outbox_feature_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$schema}");
        $this->fixtureSchema = $schema;
        DB::statement("SET search_path TO {$schema}");

        foreach (['0001_Platform_create_reliability_tables', '0081_Platform_add_outbox_correlation'] as $file) {
            $migration = require app_path("Platform/Infrastructure/Database/Migrations/{$file}.php");
            $migration->up();
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixtureSchema !== null) {
                DB::purge('outbox_lock');
                DB::statement('RESET search_path');
                DB::statement("DROP SCHEMA {$this->fixtureSchema} CASCADE");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_dispatch_filters_before_limit_and_recovers_only_the_stale_lease(): void
    {
        Queue::fake();
        $payment = $this->record('PaymentCaptured');
        $live = $this->record('OrderPlaced');
        $stale = $this->record('OrderPlaced');
        DB::table('outbox_events')->where('id', $live)->update([
            'locked_by' => 'other-worker',
            'locked_at' => now(),
        ]);
        DB::table('outbox_events')->where('id', $stale)->update([
            'locked_by' => 'lost-worker',
            'locked_at' => now()->subMinutes(3),
        ]);

        $result = app(OutboxDispatcher::class)->dispatchBatch(limit: 1, onlyQueue: 'orders');

        $this->assertSame(['claimed' => 1, 'dispatched' => 1, 'failed' => 0, 'dead' => 0], $result);
        Queue::assertPushedOn('orders', DispatchDomainEvent::class, fn (DispatchDomainEvent $job) => $job->outboxId === $stale);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $stale)->value('dispatched_at'));
        $this->assertNull(DB::table('outbox_events')->where('id', $payment)->value('dispatched_at'));
        $this->assertSame('other-worker', DB::table('outbox_events')->where('id', $live)->value('locked_by'));
    }

    public function test_outbox_event_carries_the_causing_request_and_trace_into_the_job(): void
    {
        Queue::fake();
        Context::add('request_id', 'req-outbox-1');
        Context::add('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
        $id = $this->record('OrderPlaced');
        Context::flush();

        $this->assertSame(
            ['request_id' => 'req-outbox-1', 'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736'],
            (array) DB::table('outbox_events')->where('id', $id)->first(['request_id', 'trace_id']),
        );

        app(OutboxDispatcher::class)->dispatchBatch(onlyQueue: 'orders');

        Queue::assertPushed(DispatchDomainEvent::class, function (DispatchDomainEvent $job) use ($id): bool {
            Event::fake();
            $job->handle();

            return $job->outboxId === $id
                && $job->requestId === 'req-outbox-1'
                && Context::get('request_id') === 'req-outbox-1'
                && Context::get('trace_id') === '4bf92f3577b34da6a3ce929d0e0e4736'
                && Context::get('event_name') === 'OrderPlaced';
        });
    }

    public function test_scheduled_dispatch_drains_a_canonical_queue_and_refuses_other_names(): void
    {
        $this->assertTrue(collect(app(Schedule::class)->events())->contains(
            fn ($event) => str_ends_with($event->command ?? '', ' platform:outbox:dispatch') && $event->expression === '* * * * *',
        ));

        Queue::fake();
        $id = $this->record('OrderPlaced');
        $this->artisan('platform:outbox:dispatch', ['--queue' => 'search'])->assertFailed();
        Queue::assertNothingPushed();

        $this->artisan('platform:outbox:dispatch', ['--queue' => 'orders'])
            ->expectsOutput('claimed=1 dispatched=1 failed=0 dead=0 queue=orders')->assertSuccessful();
        Queue::assertPushedOn('orders', DispatchDomainEvent::class, fn (DispatchDomainEvent $job) => $job->outboxId === $id);
    }

    public function test_discarding_queue_does_not_mark_event_delivered(): void
    {
        config(['queue.default' => 'null']);
        $eventId = $this->record('OrderPlaced');

        $result = app(OutboxDispatcher::class)->dispatchBatch();
        $event = DB::table('outbox_events')->find($eventId);

        $this->assertSame(['claimed' => 1, 'dispatched' => 0, 'failed' => 1, 'dead' => 0], $result);
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->dispatched_at);
        $this->assertNull($event->locked_by);
        $this->assertNotEmpty($event->last_error);
    }

    public function test_dispatch_skips_a_row_held_by_another_postgresql_connection(): void
    {
        Queue::fake();
        $lockedId = $this->record('OrderPlaced');
        $unlockedId = $this->record('OrderPlaced');
        config(['database.connections.outbox_lock' => config('database.connections.pgsql')]);
        $lockingConnection = DB::connection('outbox_lock');
        $lockingConnection->statement("SET search_path TO {$this->fixtureSchema}");
        $lockingConnection->beginTransaction();

        try {
            $lockingConnection->table('outbox_events')->where('id', $lockedId)->lockForUpdate()->first();
            $result = app(OutboxDispatcher::class)->dispatchBatch(limit: 1);
        } finally {
            $lockingConnection->rollBack();
        }

        $this->assertSame(1, $result['dispatched']);
        Queue::assertPushedOn('orders', DispatchDomainEvent::class, fn (DispatchDomainEvent $job) => $job->outboxId === $unlockedId);
        $this->assertSame(0, DB::table('outbox_events')->where('id', $lockedId)->value('attempts'));
    }

    public function test_other_dispatcher_cannot_publish_before_commit_or_after_rollback(): void
    {
        Queue::fake();
        config(['database.connections.outbox_lock' => config('database.connections.pgsql')]);
        DB::connection('outbox_lock')->statement("SET search_path TO {$this->fixtureSchema}");
        $dispatcher = new OutboxDispatcher('outbox_lock');
        $writer = app(OutboxWriter::class);

        foreach ([false, true] as $commit) {
            DB::beginTransaction();
            try {
                $id = $writer->record(new DomainEvent('OrderPlaced', 'order', 1));
                $this->assertSame(0, $dispatcher->dispatchBatch(onlyQueue: 'orders')['claimed']);
                Queue::assertNothingPushed();
                $commit ? DB::commit() : DB::rollBack();
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }

            $this->assertSame($commit ? 1 : 0, $dispatcher->dispatchBatch(onlyQueue: 'orders')['dispatched']);
        }

        Queue::assertPushedOn('orders', DispatchDomainEvent::class, fn (DispatchDomainEvent $job) => $job->outboxId === $id);
        Queue::assertPushed(DispatchDomainEvent::class, 1);
    }

    public function test_worker_recovery_and_replayed_delivery_produce_one_durable_effect(): void
    {
        $id = $this->record('OrderPlaced');
        DB::statement('CREATE TABLE test_order_effects (event_id uuid NOT NULL)');
        Event::listen('OrderPlaced', static function (array $event): void {
            app(InboxConsumer::class)->run('order-projection-test', $event['event_id'], $event, static function (ConnectionInterface $db) use ($event): void {
                $db->table('test_order_effects')->insert(['event_id' => $event['event_id']]);
            });
        });

        config(['queue.default' => 'null']);
        $dispatcher = app(OutboxDispatcher::class);
        $this->assertSame(1, $dispatcher->dispatchBatch(onlyQueue: 'orders')['failed']);
        $this->assertDatabaseCount('test_order_effects', 0);
        $this->assertNull(DB::table('outbox_events')->where('id', $id)->value('dispatched_at'));

        config(['queue.default' => 'sync']);
        $this->assertSame(1, $dispatcher->dispatchBatch(onlyQueue: 'orders')['dispatched']);
        $this->assertSame(0, $dispatcher->dispatchBatch(onlyQueue: 'orders')['claimed']);
        $job = new DispatchDomainEvent($id, 'OrderPlaced', 'order', 1);
        $job->handle();
        $this->assertDatabaseCount('test_order_effects', 1);
        $this->assertDatabaseHas('test_order_effects', ['event_id' => $job->eventId()]);
        $this->assertDatabaseHas('inbox_events', ['consumer' => 'order-projection-test', 'event_id' => $job->eventId(), 'status' => 'processed']);
    }

    public function test_event_cannot_be_written_without_its_domain_transaction(): void
    {
        $this->expectException(LogicException::class);
        app(OutboxWriter::class)->record(new DomainEvent('OrderPlaced', 'order', 1));
    }

    private function record(string $eventName): int
    {
        return DB::transaction(fn () => app(OutboxWriter::class)->record(new DomainEvent($eventName, 'order', 1)));
    }

    public function test_job_serialized_before_correlation_columns_still_handles(): void
    {
        $serialized = serialize(new DispatchDomainEvent(5, 'OrderPlaced', 'order', 9));
        $legacy = preg_replace_callback('/^O:(\d+):"([^"]+)":(\d+):/', static fn (array $m): string => 'O:'.$m[1].':"'.$m[2].'":'.((int) $m[3] - 2).':', $serialized);
        $legacy = str_replace(['s:9:"requestId";N;', 's:7:"traceId";N;'], '', (string) $legacy);
        $this->assertNotSame($serialized, $legacy);
        $job = unserialize($legacy);
        $this->assertInstanceOf(DispatchDomainEvent::class, $job);
        Event::fake(['OrderPlaced']);

        $job->handle();

        Event::assertDispatched('OrderPlaced');
    }
}
