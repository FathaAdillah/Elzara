<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Platform\Domain\Statuses\OrderStatus;
use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\FulfillmentLane;
use App\Platform\StateMachine\TransitionGraph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateMachineMatrixTest extends TestCase
{
    #[DataProvider('axisMatrices')]
    public function test_each_pair_is_accepted_only_when_it_is_in_the_axis_matrix(Axis $axis, array $matrix): void
    {
        $this->assertMatrix($axis, $matrix);
    }

    public static function axisMatrices(): iterable
    {
        yield 'order' => [Axis::Order, [
            'awaiting_payment' => ['paid', 'on_hold', 'expired', 'cancelled'],
            'paid' => ['processing', 'on_hold', 'refund_requested', 'cancelled'],
            'processing' => ['shipped', 'on_hold', 'refund_requested', 'cancelled'],
            'on_hold' => ['paid', 'processing', 'disputed', 'cancelled'],
            'shipped' => ['delivered', 'refund_requested', 'disputed'],
            'delivered' => ['completed', 'refund_requested', 'disputed'],
            'refund_requested' => ['refund_pending', 'disputed'],
            'refund_pending' => ['refunded', 'disputed'],
            'disputed' => ['refund_pending', 'completed', 'cancelled'],
        ]];
        yield 'payment' => [Axis::Payment, [
            'unpaid' => ['partially_paid', 'paid', 'disputed'],
            'partially_paid' => ['paid', 'overdue', 'disputed'],
            'paid' => ['refund_pending', 'refunded', 'disputed'],
            'overdue' => ['partially_paid', 'paid', 'disputed'],
            'refund_pending' => ['refunded', 'disputed'],
            'refunded' => ['disputed'],
        ]];
        yield 'procurement' => [Axis::Procurement, [
            'not_required' => ['sourcing'],
            'sourcing' => ['requisitioned', 'failed'],
            'requisitioned' => ['po_issued', 'failed'],
            'po_issued' => ['supplier_confirmed', 'failed'],
            'supplier_confirmed' => ['in_transit', 'failed'],
            'in_transit' => ['customs', 'failed'],
            'customs' => ['received', 'failed'],
        ]];
    }

    #[DataProvider('fulfillmentLanes')]
    public function test_full_lane_rejects_skips_and_other_lanes_steps(FulfillmentLane $lane, array $chain): void
    {
        $matrix = [];
        foreach ($chain as $index => $status) {
            if (isset($chain[$index + 1])) {
                $matrix[$status] = [$chain[$index + 1]];
            }
        }

        $this->assertMatrix(Axis::Fulfillment, $matrix, $lane);
    }

    public static function fulfillmentLanes(): iterable
    {
        $tail = ['picking', 'packed', 'handed_over', 'in_transit', 'delivered', 'returned'];
        yield 'ready stock' => [FulfillmentLane::ReadyToShip, ['unprocessed', 'reserved', ...$tail]];
        yield 'import pre-order' => [FulfillmentLane::ImportPreOrder, ['unprocessed', 'awaiting_batch', 'at_warehouse', 'ready_to_ship', ...$tail]];
        yield 'request order' => [FulfillmentLane::Request, ['unprocessed', 'reserved', ...$tail]];
        yield 'marketplace' => [FulfillmentLane::MarketplacePurchase, ['unprocessed', 'handed_over', 'in_transit', 'delivered']];
    }

    public function test_only_expired_ready_stock_can_release_a_reservation_instead_of_picking(): void
    {
        foreach (FulfillmentLane::cases() as $lane) {
            foreach (OrderStatus::cases() as $status) {
                $this->assertSame(
                    $lane === FulfillmentLane::ReadyToShip && $status === OrderStatus::Expired,
                    TransitionGraph::allows(Axis::Fulfillment, 'reserved', 'unprocessed', $lane, $status),
                );
            }
        }

        $this->assertFalse(TransitionGraph::allows(Axis::Fulfillment, 'reserved', 'picking', FulfillmentLane::ReadyToShip, OrderStatus::Expired));
    }

    /** @param array<string, list<string>> $matrix */
    private function assertMatrix(Axis $axis, array $matrix, ?FulfillmentLane $lane = null): void
    {
        $values = [...$axis->values(), 'invalid_status'];
        foreach ($values as $from) {
            foreach ($values as $to) {
                $this->assertSame(
                    in_array($to, $matrix[$from] ?? [], true),
                    TransitionGraph::allows($axis, $from, $to, $lane),
                    "{$axis->value}: {$from} -> {$to}",
                );
            }
        }
    }
}
