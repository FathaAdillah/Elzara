<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

final readonly class CartView
{
    /** @param list<CartGroup> $groups */
    public function __construct(public ?string $id, public array $groups, public Money $subtotal, public string $currency, public ?string $expires_at, public string $items_hash) {}
}
