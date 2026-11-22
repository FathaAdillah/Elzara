<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Contracts;

use Carbon\CarbonInterface;

interface StockReservations
{
    /** @param list<array{id:int, variant:int, qty:int}> $orderItems */
    public function hold(int $orderId, array $orderItems, CarbonInterface $expires): void;

    /** @return list<int> */
    public function dueOrders(CarbonInterface $now, int $limit): array;

    public function expire(int $order, CarbonInterface $now): void;

    public function commit(int $order, CarbonInterface $now): void;
}
