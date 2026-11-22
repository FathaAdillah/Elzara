<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceSessionSurface
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $configured = config('surfaces.admin_origin');
        $origin = SurfacePolicy::origin($configured);
        if ($origin === null) {
            if (app()->environment('production') || ($configured !== null && $configured !== '')) {
                return $this->reject(503, 'ADMIN_ORIGIN_UNAVAILABLE');
            }

            return $next($request);
        }

        $adminHost = $request->getHttpHost() === SurfacePolicy::authority($origin);
        if (SurfacePolicy::isAdmin($request)) {
            if (! $adminHost) {
                return $this->reject(403, 'ADMIN_HOST_REQUIRED');
            }
            $hasOrigin = $request->headers->has('Origin');
            $hasReferer = $request->headers->has('Referer');
            if (($hasOrigin && SurfacePolicy::origin($request->header('Origin')) !== $origin)
                || ($hasReferer && SurfacePolicy::origin($request->header('Referer'), true) !== $origin)
                || (! $request->isMethodSafe() && ! $request->isMethod('OPTIONS') && ! $hasOrigin && ! $hasReferer)) {
                return $this->reject(403, 'ADMIN_ORIGIN_REQUIRED');
            }
        } elseif ($adminHost && $request->is('api/*', 'sanctum/csrf-cookie')) {
            return $this->reject(403, 'STOREFRONT_HOST_REQUIRED');
        }

        return $next($request);
    }

    private function reject(int $status, string $code): Response
    {
        return ApiProblem::make('authorization', 'session-surface', 'Session surface rejected', $status, $code)->toResponse();
    }
}
