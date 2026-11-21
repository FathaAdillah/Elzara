<?php

declare(strict_types=1);

$buckets = [
    'product-media' => 'public',
    'request-attachments' => 'private',
    'invoice-documents' => 'private',
    'shipment-documents' => 'private',
    'exports' => 'private',
    'audit-archives' => 'private',
];

// MINIO_ENDPOINT adalah nama di sisi API; AWS_ENDPOINT_URL tetap dipakai SDK dan docker/compose.yaml — keduanya internal (ADR-008 K-7).
$internalEndpoint = (string) (env('MINIO_ENDPOINT') ?: env('AWS_ENDPOINT_URL') ?: 'http://minio:9000');

$disks = [
    'local' => [
        'driver' => 'local',
        'root' => storage_path('app/private'),
        'serve' => true,
        'throw' => true,
        'report' => false,
    ],
];

foreach ($buckets as $bucket => $visibility) {
    $disks[$bucket] = [
        'driver' => 's3',
        'key' => env('ELZARA_S3_ACCESS_KEY'),
        'secret' => env('ELZARA_S3_SECRET_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'id-jkt-1'),
        'bucket' => $bucket,
        'endpoint' => $internalEndpoint,
        'version' => 'latest',
        // path-style wajib di MinIO: object key menjadi /<bucket>/<uuid>, bukan host virtual (ADR-008 K-3)
        'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', true),
        'visibility' => $visibility,
        'throw' => true,
        'report' => false,
    ];
}

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => $disks,

    // Dua endpoint wajib ada (ADR-008 K-7): SDK bicara ke internal, presign menandatangani host yang bisa dijangkau browser.
    's3' => [
        'internal_endpoint' => $internalEndpoint,
        'presign_endpoint' => (string) env('MINIO_PUBLIC_ENDPOINT', 'https://storage.localhost'),
        'presign_tls_verify' => (bool) env('MINIO_PUBLIC_TLS_VERIFY', false),
        'presign_expiry_seconds' => (int) env('MINIO_PRESIGN_EXPIRY_SECONDS', 600),
    ],

    'links' => [],
];
