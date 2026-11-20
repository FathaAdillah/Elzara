<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use App\Platform\Domain\Statuses\FulfillmentStatus;
use App\Platform\Domain\Statuses\OrderStatus;
use App\Platform\Domain\Statuses\PaymentStatus;
use App\Platform\Domain\Statuses\ProcurementStatus;

/** Empat sumbu B-3, bukan lima: `fulfillment_lane` hanyalah pemilih tahap (Doc 01 §5.4). */
enum Axis: string
{
    case Order = 'order_status';
    case Payment = 'payment_status';
    case Fulfillment = 'fulfillment_status';
    case Procurement = 'procurement_status';

    /** @return class-string<OrderStatus|PaymentStatus|FulfillmentStatus|ProcurementStatus> */
    public function enumClass(): string
    {
        return match ($this) {
            self::Order => OrderStatus::class,
            self::Payment => PaymentStatus::class,
            self::Fulfillment => FulfillmentStatus::class,
            self::Procurement => ProcurementStatus::class,
        };
    }

    /** @return list<string> */
    public function values(): array
    {
        $class = $this->enumClass();

        return $class::values();
    }

    public function isValue(string $status): bool
    {
        return in_array($status, $this->values(), true);
    }
}
