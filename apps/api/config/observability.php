<?php

declare(strict_types=1);

return [
    // Nama layanan di setiap baris log JSON; worker/scheduler memakai image yang sama sehingga dibedakan lewat env.
    'service_name' => env('OTEL_SERVICE_NAME', 'elzara-api'),
    'metrics_redis_connection' => env('METRICS_REDIS_CONNECTION', 'default'),
];
