<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use InvalidArgumentException;

/** Tujuh dan hanya tujuh antrian (Doc 11 §8.1); MAP adalah register Doc 12 §6.2, satu event tepat satu antrian. */
final class QueueRouting
{
    public const QUEUES = ['payments', 'orders', 'inventory', 'notifications', 'imports', 'reports', 'media'];

    /** @var array<string, string> */
    public const MAP = [
        'PaymentCaptured' => 'payments',
        'RefundCompleted' => 'payments',
        'DepositPaid' => 'payments',
        'BalanceInvoiced' => 'payments',
        'OrderPlaced' => 'orders',
        'OrderCancelled' => 'orders',
        'PaidStockExceptionRaised' => 'orders',
        'QuoteAccepted' => 'orders',
        'ReturnDecided' => 'orders',
        'PackageShipped' => 'orders',
        'ShipmentDelivered' => 'orders',
        'TrackingUpdated' => 'orders',
        'StockReserved' => 'inventory',
        'ReservationExpired' => 'inventory',
        'StockLow' => 'inventory',
        'GoodsReceived' => 'inventory',
        'UserRegistered' => 'notifications',
        'QuoteIssued' => 'notifications',
        'QuoteExpired' => 'notifications',
        'ProductUpdated' => 'imports',
        'BatchClosed' => 'imports',
        'LandedCostAllocated' => 'imports',
        'NotificationDelivered' => 'reports',
        'MediaValidated' => 'media',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::MAP);
    }

    public static function isQueue(string $queue): bool
    {
        return in_array($queue, self::QUEUES, true);
    }

    public static function for(string $eventName): string
    {
        return self::MAP[$eventName] ?? throw new InvalidArgumentException(
            "Event '{$eventName}' belum dipetakan ke salah satu dari tujuh antrian (Doc 11 §8.1).",
        );
    }

    /** @return array<string, list<string>> */
    public static function byQueue(): array
    {
        $grouped = array_fill_keys(self::QUEUES, []);

        foreach (self::MAP as $event => $queue) {
            $grouped[$queue][] = $event;
        }

        return $grouped;
    }
}
