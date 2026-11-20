<?php

declare(strict_types=1);

use App\ModuleLoader\ModuleServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
];
