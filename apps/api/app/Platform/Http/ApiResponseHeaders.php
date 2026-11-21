<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponseHeaders
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! AttachRequestId::appliesTo($request)) {
            return $response;
        }
        if (! $request->attributes->has('rate_headers')) {
            $policy = match (true) {
                $request->is('api/v1/admin/auth/login', 'api/v1/admin/auth/2fa/verify') => 'admin-auth',
                $request->is('api/v1/admin/*') => 'admin',
                $request->is('api/v1/webhooks/*') => 'webhooks',
                in_array('rate:web', $request->route()?->gatherMiddleware() ?? [], true) => 'web',
                default => 'public',
            };
            $limit = RatePolicy::limit($request, $policy);
            $key = 'api-rate:'.RatePolicy::key($request, $policy);
            $request->attributes->set('rate_headers', [
                'X-RateLimit-Limit' => (string) $limit->maxAttempts,
                'X-RateLimit-Remaining' => (string) $this->limiter->remaining($key, $limit->maxAttempts),
                'X-RateLimit-Reset' => (string) (time() + ($this->limiter->availableIn($key) ?: 60)),
                'X-RateLimit-Policy' => $policy,
            ]);
        }
        /** @var array<string, string> $headers */
        $headers = $request->attributes->get('rate_headers');
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        $user = Auth::guard('admin')->user() ?? Auth::guard('web')->user();
        if ($user instanceof Model) {
            $timezone = $user->getAttribute('timezone');
            if (is_string($timezone)) {
                $response->headers->set('X-Displayed-Timezone', $timezone);
            }
        }
        $timezone = $request->attributes->get('displayed_timezone');
        if (! $response->headers->has('X-Displayed-Timezone') && is_string($timezone)) {
            $response->headers->set('X-Displayed-Timezone', $timezone);
        }

        return $response;
    }
}
