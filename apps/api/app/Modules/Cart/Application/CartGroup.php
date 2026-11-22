<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

final class CartGroup
{
    /** @param list<CartLine> $items */
    public function __construct(public string $mode, public ?string $eta, public array $items = [], public Money $subtotal = new Money(0)) {}
}
