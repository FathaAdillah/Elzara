<?php

declare(strict_types=1);

use App\ModuleLoader\ModuleServiceProvider;
use App\Platform\Http\ApiServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ApiServiceProvider::class,
    ModuleServiceProvider::class,
];
