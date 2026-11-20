<?php

declare(strict_types=1);

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\OpenApiVersion;

// The generated document is a draft for review. The canonical spec lives in packages/api-contracts.
return [
    'api_path' => 'api/v1',
    'openapi_version' => OpenApiVersion::V3_1,
    'dev_tools' => [
        'enabled' => false,
    ],
    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],
    // Auth metadata in the canonical contract describes session cookies and CSRF.
    'security_strategy' => null,
];
