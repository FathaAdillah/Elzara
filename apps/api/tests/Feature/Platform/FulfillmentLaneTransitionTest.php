<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\InvalidTransition;
use App\Platform\StateMachine\StateMachineRunner;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FulfillmentLaneTransitionTest extends TestCase
{
    private const TABLE = 'fulfillment_lane_transition_fixture';

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('CREATE TEMPORARY TABLE fulfillment_lane_transition_fixture (
            id bigint PRIMARY KEY,
            fulfillment_lane varchar(20) NOT NULL,
            fulfillment_status varchar(32) NOT NULL,
            updated_at timestamp NOT NULL
        )');
        DB::table(self::TABLE)->insert([
            ['id' => 1, 'fulfillment_lane' => 'marketplace_purchase', 'fulfillment_status' => 'unprocessed', 'updated_at' => now()],
            ['id' => 2, 'fulfillment_lane' => 'rts', 'fulfillment_status' => 'unprocessed', 'updated_at' => now()],
        ]);
    }

    public function test_marketplace_lane_allows_direct_handover(): void
    {
        $result = app(StateMachineRunner::class)->transition(
            self::TABLE,
            1,
            Axis::Fulfillment,
            'handed_over',
        );

        $this->assertSame('handed_over', $result);
        $this->assertSame('handed_over', DB::table(self::TABLE)->where('id', 1)->value('fulfillment_status'));
    }

    public function test_rts_lane_rejects_marketplace_transition_without_changing_state(): void
    {
        try {
            app(StateMachineRunner::class)->transition(
                self::TABLE,
                2,
                Axis::Fulfillment,
                'handed_over',
            );
            $this->fail('RTS fulfillment must reject direct handover.');
        } catch (InvalidTransition $exception) {
            $this->assertSame(Axis::Fulfillment, $exception->axis);
            $this->assertSame('unprocessed', $exception->from);
            $this->assertSame('handed_over', $exception->to);
        }

        $this->assertSame('unprocessed', DB::table(self::TABLE)->where('id', 2)->value('fulfillment_status'));
    }
}
