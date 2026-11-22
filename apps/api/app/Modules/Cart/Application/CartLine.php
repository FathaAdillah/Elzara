<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

final readonly class CartLine
{
    public function __construct(public string $id, public string $variant_id, public string $name, public int $quantity, public Money $unit_price, public Money $line_total, public bool $price_changed, public bool $unavailable, public ?int $weight_g) {}
}
