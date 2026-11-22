<?php

return [
    'session_ttl_minutes' => (int) env('CHECKOUT_SESSION_TTL_MINUTES', 120),
    'reservation_ttl_minutes' => (int) env('CHECKOUT_RESERVATION_TTL_MINUTES', 120),
    'warehouse_code' => env('CHECKOUT_WAREHOUSE_CODE', 'DEMO'),
    'fake_payment_enabled' => (bool) env('CHECKOUT_FAKE_PAYMENT_ENABLED', false),
    'origin_country' => 'ID',
];
