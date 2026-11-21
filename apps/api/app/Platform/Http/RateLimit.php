<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RateLimit
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $policy): Response
    {
        abort_unless(in_array($policy, RatePolicy::NAMES, true), 500);
        $limit = RatePolicy::limit($request, $policy);
        $key = 'api-rate:'.RatePolicy::key($request, $policy);
        $exceeded = $this->limiter->tooManyAttempts($key, $limit->maxAttempts);
        if (! $exceeded) {
            $this->limiter->hit($key, 60);
        }
        $headers = [
            'X-RateLimit-Limit' => (string) $limit->maxAttempts,
            'X-RateLimit-Remaining' => (string) $this->limiter->remaining($key, $limit->maxAttempts),
            'X-RateLimit-Reset' => (string) (time() + $this->limiter->availableIn($key)),
            'X-RateLimit-Policy' => $policy,
        ];
        $request->attributes->set('rate_headers', $headers);
        $user = Auth::guard($policy === 'admin' || $policy === 'admin-auth' ? 'admin' : 'web')->user();
        if ($user instanceof Model && is_string($user->getAttribute('timezone'))) {
            $request->attributes->set('displayed_timezone', $user->getAttribute('timezone'));
        }
        if ($exceeded) {
            abort(429, '', ['Retry-After' => (string) max(1, $this->limiter->availableIn($key))]);
        }

        return $next($request);
    }
}
