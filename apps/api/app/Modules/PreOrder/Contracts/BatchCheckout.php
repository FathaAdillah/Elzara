<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Contracts;

use Carbon\CarbonInterface;

interface BatchCheckout
{
    public function offer(int $variant): BatchOffer;

    public function enroll(BatchOffer $offer, int $user, int $order, int $item, int $quantity, CarbonInterface $expires): int;

    public function capture(int $order, CarbonInterface $now): int;

    /** @return list<int> */
    public function dueOrders(CarbonInterface $now, int $limit): array;

    public function expire(int $order, CarbonInterface $now): void;
}
