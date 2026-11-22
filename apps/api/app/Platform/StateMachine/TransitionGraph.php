<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use App\Platform\Domain\Statuses\FulfillmentStatus;
use App\Platform\Domain\Statuses\OrderStatus;
use App\Platform\Domain\Statuses\PaymentStatus;
use App\Platform\Domain\Statuses\ProcurementStatus;

/** Graf deklaratif per sumbu (Doc 01 §5.2); nilai selalu diambil dari enum, tidak ada daftar string kedua (D-S0-4). */
final class TransitionGraph
{
    /** @var array<string, array<string, list<string>>>|null */
    private static ?array $edges = null;

    public static function allows(Axis $axis, string $from, string $to, ?FulfillmentLane $lane = null, ?OrderStatus $orderStatus = null): bool
    {
        return in_array($to, self::allowedTargets($axis, $from, $lane, $orderStatus), true);
    }

    /** @return list<string> */
    public static function allowedTargets(Axis $axis, string $from, ?FulfillmentLane $lane = null, ?OrderStatus $orderStatus = null): array
    {
        if ($axis === Axis::Fulfillment && $lane !== null) {
            if ($lane === FulfillmentLane::ReadyToShip && $orderStatus === OrderStatus::Expired && $from === FulfillmentStatus::Reserved->value) {
                return [FulfillmentStatus::Unprocessed->value];
            }

            $stages = $lane->stages();
            $index = array_search(FulfillmentStatus::tryFrom($from), $stages, true);
            if ($index === false || ! isset($stages[$index + 1])) {
                return [];
            }

            return [$stages[$index + 1]->value];
        }

        return self::edges($axis)[$from] ?? [];
    }

    /** @return array<string, list<string>> */
    private static function edges(Axis $axis): array
    {
        return (self::$edges ??= self::build())[$axis->value];
    }

    /** @return array<string, array<string, list<string>>> */
    private static function build(): array
    {
        return [
            Axis::Order->value => [
                OrderStatus::AwaitingPayment->value => [
                    OrderStatus::Paid->value, OrderStatus::OnHold->value,
                    OrderStatus::Expired->value, OrderStatus::Cancelled->value,
                ],
                OrderStatus::Paid->value => [
                    OrderStatus::Processing->value, OrderStatus::OnHold->value,
                    OrderStatus::RefundRequested->value, OrderStatus::Cancelled->value,
                ],
                OrderStatus::Processing->value => [
                    OrderStatus::Shipped->value, OrderStatus::OnHold->value,
                    OrderStatus::RefundRequested->value, OrderStatus::Cancelled->value,
                ],
                OrderStatus::OnHold->value => [
                    OrderStatus::Paid->value, OrderStatus::Processing->value,
                    OrderStatus::Disputed->value, OrderStatus::Cancelled->value,
                ],
                OrderStatus::Shipped->value => [
                    OrderStatus::Delivered->value, OrderStatus::RefundRequested->value,
                    OrderStatus::Disputed->value,
                ],
                OrderStatus::Delivered->value => [
                    OrderStatus::Completed->value, OrderStatus::RefundRequested->value,
                    OrderStatus::Disputed->value,
                ],
                OrderStatus::RefundRequested->value => [
                    OrderStatus::RefundPending->value, OrderStatus::Disputed->value,
                ],
                OrderStatus::RefundPending->value => [OrderStatus::Refunded->value, OrderStatus::Disputed->value],
                OrderStatus::Disputed->value => [
                    OrderStatus::RefundPending->value, OrderStatus::Completed->value, OrderStatus::Cancelled->value,
                ],
            ],
            Axis::Payment->value => [
                PaymentStatus::Unpaid->value => [
                    PaymentStatus::PartiallyPaid->value, PaymentStatus::Paid->value, PaymentStatus::Disputed->value,
                ],
                PaymentStatus::PartiallyPaid->value => [
                    PaymentStatus::Paid->value, PaymentStatus::Overdue->value, PaymentStatus::Disputed->value,
                ],
                PaymentStatus::Paid->value => [
                    PaymentStatus::RefundPending->value, PaymentStatus::Refunded->value, PaymentStatus::Disputed->value,
                ],
                PaymentStatus::Overdue->value => [
                    PaymentStatus::PartiallyPaid->value, PaymentStatus::Paid->value, PaymentStatus::Disputed->value,
                ],
                PaymentStatus::RefundPending->value => [PaymentStatus::Refunded->value, PaymentStatus::Disputed->value],
                PaymentStatus::Refunded->value => [PaymentStatus::Disputed->value],
            ],
            // Gabungan untuk pemeriksaan graf tanpa konteks lane. Runner memakai lane dari baris terkunci.
            Axis::Fulfillment->value => [
                FulfillmentStatus::Unprocessed->value => [
                    FulfillmentStatus::Reserved->value, FulfillmentStatus::AwaitingBatch->value,
                    FulfillmentStatus::HandedOver->value,
                ],
                FulfillmentStatus::Reserved->value => [FulfillmentStatus::Picking->value],
                FulfillmentStatus::AwaitingBatch->value => [FulfillmentStatus::AtWarehouse->value],
                FulfillmentStatus::AtWarehouse->value => [FulfillmentStatus::ReadyToShip->value],
                FulfillmentStatus::ReadyToShip->value => [FulfillmentStatus::Picking->value],
                FulfillmentStatus::Picking->value => [FulfillmentStatus::Packed->value],
                FulfillmentStatus::Packed->value => [FulfillmentStatus::HandedOver->value],
                FulfillmentStatus::HandedOver->value => [FulfillmentStatus::InTransit->value],
                FulfillmentStatus::InTransit->value => [
                    FulfillmentStatus::Delivered->value, FulfillmentStatus::Returned->value,
                ],
                FulfillmentStatus::Delivered->value => [FulfillmentStatus::Returned->value],
            ],
            Axis::Procurement->value => [
                ProcurementStatus::NotRequired->value => [ProcurementStatus::Sourcing->value],
                ProcurementStatus::Sourcing->value => [
                    ProcurementStatus::Requisitioned->value, ProcurementStatus::Failed->value,
                ],
                ProcurementStatus::Requisitioned->value => [
                    ProcurementStatus::PoIssued->value, ProcurementStatus::Failed->value,
                ],
                ProcurementStatus::PoIssued->value => [
                    ProcurementStatus::SupplierConfirmed->value, ProcurementStatus::Failed->value,
                ],
                ProcurementStatus::SupplierConfirmed->value => [
                    ProcurementStatus::InTransit->value, ProcurementStatus::Failed->value,
                ],
                ProcurementStatus::InTransit->value => [
                    ProcurementStatus::Customs->value, ProcurementStatus::Failed->value,
                ],
                ProcurementStatus::Customs->value => [
                    ProcurementStatus::Received->value, ProcurementStatus::Failed->value,
                ],
            ],
        ];
    }
}
