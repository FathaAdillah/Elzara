<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use App\Platform\Http\AttachRequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final class RecordRequestMetrics
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! AttachRequestId::appliesTo($request) && ! $request->is('sanctum/csrf-cookie')) {
            return $next($request);
        }

        $started = hrtime(true);
        $response = $next($request);
        $seconds = (hrtime(true) - $started) / 1e9;

        $route = $request->route();
        $labels = [
            'method' => $request->getMethod(),
            'route' => $route instanceof Route ? '/'.ltrim($route->uri(), '/') : 'unmatched',
        ];

        Metrics::increment('elzara_http_requests_total', $labels + ['status' => (string) $response->getStatusCode()]);
        Metrics::observe('elzara_http_request_duration_seconds', $seconds, $labels);

        return $response;
    }
}
