<?php

declare(strict_types=1);

return [
    'driver' => env('SHIPPING_DRIVER', 'manual'),
    'cache_ttl_seconds' => (int) env('SHIPPING_CACHE_TTL_SECONDS', 1800),
    'biteship' => [
        'key' => env('BITESHIP_API_KEY', ''),
        'base_url' => env('BITESHIP_BASE_URL', 'https://api.biteship.com'),
        'origin_postcode' => env('BITESHIP_ORIGIN_POSTCODE', ''),
        'couriers' => env('BITESHIP_COURIERS', 'jne,jnt'),
        'timeout_seconds' => (int) env('BITESHIP_TIMEOUT_SECONDS', 10),
    ],
];
