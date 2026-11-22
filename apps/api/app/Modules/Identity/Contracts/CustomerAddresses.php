<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

interface CustomerAddresses
{
    public function owned(int $userId, string $publicId): AddressData;
}
