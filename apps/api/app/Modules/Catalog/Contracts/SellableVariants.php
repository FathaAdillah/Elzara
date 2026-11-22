<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface SellableVariants
{
    public function find(int $variantId): ?SellableVariant;
}
