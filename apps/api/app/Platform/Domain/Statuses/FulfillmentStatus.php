<?php

declare(strict_types=1);

namespace App\Platform\Domain\Statuses;

/** Sumbu 3 dari empat, pemilik Fulfillment; nilai `awaiting_batch`/`ready_to_ship` pindah ke sini, `customs` keluar (B-3). */
enum FulfillmentStatus: string
{
    case Unprocessed = 'unprocessed';
    case Reserved = 'reserved';
    case AwaitingBatch = 'awaiting_batch';
    case AtWarehouse = 'at_warehouse';
    case ReadyToShip = 'ready_to_ship';
    case Picking = 'picking';
    case Packed = 'packed';
    case HandedOver = 'handed_over';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Returned = 'returned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::Unprocessed;
    }
}
