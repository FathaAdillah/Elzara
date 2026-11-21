<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

final class RatePolicy
{
    public const NAMES = ['public', 'web', 'admin', 'admin-auth', 'webhooks'];

    public static function limit(Request $request, string $policy): Limit
    {
        return Limit::perMinute(max(1, Config::integer('api.rate_limits.'.$policy)))->by(self::key($request, $policy));
    }

    public static function key(Request $request, string $policy): string
    {
        $actor = match ($policy) {
            'admin' => Auth::guard('admin')->id(),
            'web' => Auth::guard('web')->id(),
            default => null,
        };
        $scope = $actor !== null ? 'account:'.$actor : 'ip:'.$request->ip();
        if ($policy === 'webhooks') {
            $scope = 'provider:'.$request->segment(4).'|'.$scope;
        }

        return $policy.'|'.$scope;
    }
}
