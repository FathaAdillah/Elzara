<?php

declare(strict_types=1);

return [
    'max_bytes' => 20 * 1024 * 1024,
    'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
    'upload_buckets' => ['product-media', 'request-attachments', 'invoice-documents', 'shipment-documents'],
];
