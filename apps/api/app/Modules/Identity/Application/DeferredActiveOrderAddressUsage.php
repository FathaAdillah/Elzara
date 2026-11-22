<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

final class DeferredActiveOrderAddressUsage implements ActiveOrderAddressUsage
{
    public function isUsedByActiveOrder(int $addressId): bool
    {
        return false;
    }
}
