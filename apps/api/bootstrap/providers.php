<?php

declare(strict_types=1);

use App\ModuleLoader\ModuleServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Platform\Http\ApiServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    ApiServiceProvider::class,
    ModuleServiceProvider::class,
];
