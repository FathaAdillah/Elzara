<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

final readonly class ShippingRate
{
    public function __construct(
        public string $courierCode,
        public string $serviceCode,
        public string $serviceName,
        public int $amountMinor,
        public string $currency,
        public ?int $etaMinDays,
        public ?int $etaMaxDays,
        public string $provider,
    ) {
        if ($amountMinor < 0 || $currency !== 'IDR' || $courierCode === '' || $serviceCode === '' || $serviceName === ''
            || ($etaMinDays !== null && $etaMinDays < 0) || ($etaMaxDays !== null && ($etaMinDays === null || $etaMaxDays < $etaMinDays))) {
            throw new ProviderUnavailable('Invalid rate returned by shipping provider.');
        }
    }
}
