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

    public const STOREFRONT_KEY_HEADER = 'X-Elzara-Storefront-Key';

    public const SHOPPER_IP_HEADER = 'X-Elzara-Shopper-Ip';

    private const MIN_STOREFRONT_KEY_LENGTH = 32;

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
        $scope = $actor !== null ? 'account:'.$actor : 'ip:'.self::clientIp($request);
        if ($policy === 'webhooks') {
            $scope = 'provider:'.implode('/', array_slice(explode('/', $request->path()), 3, 2)).'|'.$scope;
        }

        return $policy.'|'.$scope;
    }

    /**
     * Storefront SSR requests all come from the web container, so they carry the shopper's IP
     * plus the shared server key; without a valid key the header is ignored and the source IP counts.
     */
    private static function clientIp(Request $request): ?string
    {
        $key = Config::string('api.storefront_proxy_key', '');
        $presented = $request->headers->get(self::STOREFRONT_KEY_HEADER);
        if (strlen($key) < self::MIN_STOREFRONT_KEY_LENGTH || ! is_string($presented) || ! hash_equals($key, $presented)) {
            return $request->ip();
        }
        $shopper = trim((string) $request->headers->get(self::SHOPPER_IP_HEADER, ''));

        return filter_var($shopper, FILTER_VALIDATE_IP) !== false ? $shopper : $request->ip();
    }
}
