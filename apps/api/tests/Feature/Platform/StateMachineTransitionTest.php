<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Infrastructure\Database\EnumCheckConstraint;
use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\InvalidTransition;
use App\Platform\StateMachine\StateMachineRunner;
use App\Platform\StateMachine\TransitionSink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class StateMachineTransitionTest extends TestCase
{
    private const CONNECTION = 'transition_fixture';

    private const TABLE = 'transition_entities';

    private ConnectionInterface $database;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => config('database.connections.pgsql')]);
        $this->database = DB::connection(self::CONNECTION);
        $this->database->statement('CREATE TEMPORARY TABLE transition_entities (
            id bigint PRIMARY KEY,
            order_status varchar NOT NULL,
            payment_status varchar NOT NULL,
            fulfillment_status varchar NOT NULL,
            fulfillment_lane varchar NOT NULL,
            procurement_status varchar NOT NULL,
            updated_at timestamp NOT NULL
        )');
        $this->database->statement('CREATE TEMPORARY TABLE transition_audit (
            entity_table varchar NOT NULL,
            entity_id bigint NOT NULL,
            axis varchar NOT NULL,
            previous_status varchar NOT NULL,
            next_status varchar NOT NULL,
            reason varchar NOT NULL
        )');
        $checks = new EnumCheckConstraint(DB::connection(self::CONNECTION));
        foreach (Axis::cases() as $axis) {
            $checks->addAxis(self::TABLE, $axis);
        }
        $this->database->table(self::TABLE)->insert([
            'id' => 1,
            'order_status' => 'awaiting_payment',
            'payment_status' => 'unpaid',
            'fulfillment_status' => 'unprocessed',
            'fulfillment_lane' => 'rts',
            'procurement_status' => 'not_required',
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge(self::CONNECTION);
        } finally {
            parent::tearDown();
        }
    }

    public function test_all_four_axes_write_trimmed_history_on_the_selected_connection(): void
    {
        $sink = $this->sink();
        $runner = new StateMachineRunner(self::CONNECTION, $sink);

        foreach ([
            [Axis::Order, 'awaiting_payment', 'paid'],
            [Axis::Payment, 'unpaid', 'paid'],
            [Axis::Fulfillment, 'unprocessed', 'reserved'],
            [Axis::Procurement, 'not_required', 'sourcing'],
        ] as [$axis, $from, $to]) {
            $this->assertSame($to, $runner->transition(self::TABLE, 1, $axis, $to, $from, '  fixture reason  '));
            $this->assertSame($to, $this->database->table(self::TABLE)->where('id', 1)->value($axis->value));
            $this->assertSame([
                'entity_table' => self::TABLE,
                'entity_id' => 1,
                'axis' => $axis->value,
                'previous_status' => $from,
                'next_status' => $to,
                'reason' => 'fixture reason',
            ], (array) $this->database->table('transition_audit')->where('axis', $axis->value)->first());
        }

        $this->assertSame($this->database, $sink->connection);
        $this->assertSame(4, $this->database->table('transition_audit')->count());
    }

    public function test_rejected_transitions_do_not_change_the_row_or_write_history(): void
    {
        $runner = new StateMachineRunner(self::CONNECTION, $this->sink());
        $original = (array) $this->database->table(self::TABLE)->find(1);

        foreach ([
            ['processing', null],
            ['paid', 'processing'],
            ['awaiting_payment', 'awaiting_payment'],
            ['unknown', null],
        ] as [$to, $ifFrom]) {
            try {
                $runner->transition(self::TABLE, 1, Axis::Order, $to, $ifFrom, 'fixture');
                $this->fail('Expected an invalid transition.');
            } catch (InvalidTransition $failure) {
                $this->assertSame($to, $failure->to);
            }
        }

        foreach ([null, '    '] as $reason) {
            try {
                $runner->transition(self::TABLE, 1, Axis::Order, 'paid', reason: $reason);
                $this->fail('Expected a required transition reason.');
            } catch (InvalidArgumentException $failure) {
                $this->assertStringContainsString('Alasan transisi wajib', $failure->getMessage());
            }
        }

        $this->assertSame($original, (array) $this->database->table(self::TABLE)->find(1));
        $this->assertSame(0, $this->database->table('transition_audit')->count());
    }

    public function test_sink_failure_rolls_back_audit_and_transition_together(): void
    {
        $sink = $this->sink(fail: true);
        $runner = new StateMachineRunner(self::CONNECTION, $sink);
        $original = (array) $this->database->table(self::TABLE)->find(1);

        try {
            $runner->transition(self::TABLE, 1, Axis::Order, 'paid', reason: 'fixture failure');
            $this->fail('Expected the audit sink failure.');
        } catch (RuntimeException $failure) {
            $this->assertSame('fixture audit failure', $failure->getMessage());
        }

        $this->assertSame($this->database, $sink->connection);
        $this->assertSame($original, (array) $this->database->table(self::TABLE)->find(1));
        $this->assertSame(0, $this->database->table('transition_audit')->count());
    }

    private function sink(bool $fail = false): TransitionSink
    {
        return new class($fail) implements TransitionSink
        {
            public ?ConnectionInterface $connection = null;

            public function __construct(private readonly bool $fail) {}

            public function record(ConnectionInterface $connection, string $table, int $aggregateId, Axis $axis, string $from, string $to, string $reason, ?int $actorId = null): void
            {
                $this->connection = $connection;
                $connection->table('transition_audit')->insert([
                    'entity_table' => $table,
                    'entity_id' => $aggregateId,
                    'axis' => $axis->value,
                    'previous_status' => $from,
                    'next_status' => $to,
                    'reason' => $reason,
                ]);

                if ($this->fail) {
                    throw new RuntimeException('fixture audit failure');
                }
            }
        };
    }
}
