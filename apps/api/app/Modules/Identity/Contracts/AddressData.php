<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

final readonly class AddressData
{
    public function __construct(public string $id, public string $recipient, public string $phone, public string $province, public string $city, public string $district, public string $postal_code, public string $address_line, public bool $is_default) {}
}
