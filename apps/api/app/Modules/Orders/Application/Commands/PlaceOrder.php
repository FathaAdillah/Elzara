<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application\Commands;

final class PlaceOrder
{
    /** @param array<string, mixed> $cart */
    public function __construct(public readonly int|string|null $actorId, public readonly array $cart) {}

    public function handle(): void {}
}
