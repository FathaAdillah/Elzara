<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

interface ReadyStockCheckout
{
    public function prepare(int $user, string $sessionCode, string $addressCode): ReadyStockQuote;

    public function convert(int $sessionId, int $orderId): void;
}
