<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (RatePolicy::NAMES as $policy) {
            RateLimiter::for($policy, fn (Request $request) => RatePolicy::limit($request, $policy));
        }
    }
}
