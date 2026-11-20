<?php

declare(strict_types=1);

use App\Platform\Outbox\QueueRouting;

// tujuh pool terpisah (Doc 01 §10): `nice` menekan antrian non-kritis di belakang callback pembayaran dan expirasi reservasi.
$weights = [
    'payments' => ['min' => 2, 'max' => 6, 'nice' => 0],
    'inventory' => ['min' => 1, 'max' => 4, 'nice' => 0],
    'orders' => ['min' => 1, 'max' => 4, 'nice' => 1],
    'notifications' => ['min' => 1, 'max' => 2, 'nice' => 2],
    'media' => ['min' => 1, 'max' => 2, 'nice' => 3],
    'imports' => ['min' => 1, 'max' => 1, 'nice' => 5],
    'reports' => ['min' => 1, 'max' => 1, 'nice' => 5],
];

$supervisors = [];

foreach (QueueRouting::QUEUES as $queue) {
    $weight = $weights[$queue];

    $supervisors[$queue] = [
        'connection' => 'redis',
        'queue' => [$queue],
        'balance' => 'auto',
        'autoScalingStrategy' => 'time',
        'minProcesses' => $weight['min'],
        'maxProcesses' => $weight['max'],
        'balanceMaxShift' => 1,
        'balanceCooldown' => 3,
        'maxTime' => 0,
        'maxJobs' => 0,
        'memory' => 128,
        'tries' => 5,
        'timeout' => 60,
        'nice' => $weight['nice'],
    ];
}

$singleProcess = array_map(
    static fn (array $pool): array => ['minProcesses' => 1, 'maxProcesses' => 1] + $pool,
    $supervisors,
);

return [
    'name' => env('HORIZON_NAME', env('APP_NAME', 'elzara')),
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    // Horizon menyimpan metadata lewat nama koneksi Redis di config/database.php.
    'use' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'prefix' => env('HORIZON_PREFIX', 'elzara:horizon:'),
    'middleware' => ['web'],

    'waits' => [
        'redis:payments' => 15,
        'redis:orders' => 30,
        'redis:inventory' => 30,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],
    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'watch' => ['app', 'bootstrap', 'config/**/*.php', 'routes/**/*.php'],
    'history' => (int) env('HORIZON_HISTORY', 3600),
    'fast_termination' => false,
    'memory_limit' => (int) env('HORIZON_MEMORY_LIMIT', 64),

    'defaults' => [
        'supervisor-baseline' => [
            'connection' => 'redis',
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'minProcesses' => 1,
            'maxProcesses' => 1,
            'memory' => 128,
            'tries' => 5,
            'timeout' => 60,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'local' => $singleProcess,
        'staging' => $supervisors,
        'production' => $supervisors,
    ],
];
