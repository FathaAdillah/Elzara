<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

final readonly class SellableVariant
{
    public function __construct(public int $id, public string $name, public int $price, public int $available, public string $mode, public ?string $eta, public ?int $weightGrams) {}
}
