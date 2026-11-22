<?php

declare(strict_types=1);

namespace App\Modules\Cart\Contracts;

use App\Modules\Identity\Contracts\AddressData;

final readonly class ReadyStockQuote
{
    /**
     * @param  list<array{variant_id:int, qty:int, name:string, sku:string, price:int, old_price:int}>  $lines
     * @param  list<array{kind:string, variant_id?:string, from:int, to:int}>  $changes
     */
    public function __construct(
        public int $sessionId,
        public AddressData $address,
        public string $courier,
        public string $service,
        public array $lines,
        public int $subtotal,
        public int $shipping,
        public array $changes,
        public string $mode = 'rts',
    ) {}
}
