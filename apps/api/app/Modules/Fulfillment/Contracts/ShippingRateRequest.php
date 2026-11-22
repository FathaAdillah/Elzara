<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Contracts;

final readonly class ShippingRateRequest
{
    public function __construct(
        public string $originCountry,
        public string $province,
        public string $city,
        public string $district,
        public string $postcode,
        public int $weightGrams,
        public string $packageTier,
        public ?string $courier = null,
        public string $destinationCountry = 'ID',
        public bool $fresh = false,
    ) {
        if (! preg_match('/\A[A-Z]{2}\z/', $originCountry) || ! preg_match('/\A[A-Z]{2}\z/', $destinationCountry)
            || trim($province) === '' || strlen($province) > 60 || trim($city) === '' || strlen($city) > 60
            || trim($district) === '' || strlen($district) > 60 || ! preg_match('/\A[0-9A-Za-z -]{3,10}\z/', $postcode)
            || ($destinationCountry === 'ID' && ! preg_match('/\A[0-9]{5}\z/', $postcode))
            || $weightGrams < 1 || ! preg_match('/\A[a-z0-9_]{1,24}\z/', $packageTier)
            || ($courier !== null && ! preg_match('/\A[a-z0-9_]{1,32}\z/', $courier))) {
            throw new InvalidDestination('Invalid shipping destination, weight or selector.');
        }
    }
}
