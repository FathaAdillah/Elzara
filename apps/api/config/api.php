<?php

declare(strict_types=1);

return [
    'internal_cidrs' => array_filter(explode(',', (string) env('ELZARA_INTERNAL_CIDRS', '127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7'))),
    // Shared with the storefront server: SSR calls carrying it are throttled per forwarded shopper IP.
    'storefront_proxy_key' => (string) env('ELZARA_STOREFRONT_PROXY_KEY', ''),
    'rate_limits' => [
        'public' => (int) env('ELZARA_RATE_PUBLIC', 120),
        'web' => (int) env('ELZARA_RATE_WEB', 60),
        'admin' => (int) env('ELZARA_RATE_ADMIN', 120),
        'admin-auth' => (int) env('ELZARA_RATE_ADMIN_AUTH', 30),
        'webhooks' => (int) env('ELZARA_RATE_WEBHOOKS', 1000),
    ],
];
