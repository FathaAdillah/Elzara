<?php

declare(strict_types=1);

use App\ModuleLoader\ModuleServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Platform\Http\ApiServiceProvider;
use App\Platform\Observability\ObservabilityServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    ApiServiceProvider::class,
    ObservabilityServiceProvider::class,
    ModuleServiceProvider::class,
];
