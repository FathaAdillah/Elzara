<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

interface ActiveOrderAddressUsage
{
    public function isUsedByActiveOrder(int $addressId): bool;
}
