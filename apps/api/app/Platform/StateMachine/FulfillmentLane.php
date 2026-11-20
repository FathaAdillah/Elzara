<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use App\Platform\Domain\Statuses\FulfillmentStatus;

enum FulfillmentLane: string
{
    case ReadyToShip = 'rts';
    case ImportPreOrder = 'import_po';
    case Request = 'request';
    case MarketplacePurchase = 'marketplace_purchase';

    /** @return list<FulfillmentStatus> */
    public function stages(): array
    {
        $warehouseStages = [
            FulfillmentStatus::Picking,
            FulfillmentStatus::Packed,
            FulfillmentStatus::HandedOver,
            FulfillmentStatus::InTransit,
            FulfillmentStatus::Delivered,
            FulfillmentStatus::Returned,
        ];

        return match ($this) {
            self::ReadyToShip, self::Request => [
                FulfillmentStatus::Unprocessed,
                FulfillmentStatus::Reserved,
                ...$warehouseStages,
            ],
            self::ImportPreOrder => [
                FulfillmentStatus::Unprocessed,
                FulfillmentStatus::AwaitingBatch,
                FulfillmentStatus::AtWarehouse,
                FulfillmentStatus::ReadyToShip,
                ...$warehouseStages,
            ],
            self::MarketplacePurchase => [
                FulfillmentStatus::Unprocessed,
                FulfillmentStatus::HandedOver,
                FulfillmentStatus::InTransit,
                FulfillmentStatus::Delivered,
            ],
        };
    }
}
