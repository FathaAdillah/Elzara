<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AttachRequestId
{
    public const HEADER = 'X-Request-Id';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::appliesTo($request)) {
            return $next($request);
        }

        $requestId = self::getOrAssign($request);
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/v1', 'api/v1/*');
    }

    public static function getOrAssign(Request $request): string
    {
        $requestId = $request->attributes->get('request_id');
        if (is_string($requestId)) {
            return $requestId;
        }

        $incoming = $request->headers->all(self::HEADER);
        $candidate = $incoming[0] ?? null;
        $requestId = count($incoming) === 1 && $candidate !== null && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $candidate) === 1
            ? $candidate
            : (string) Str::ulid();

        $request->attributes->set('request_id', $requestId);
        Context::add('request_id', $requestId);

        return $requestId;
    }
}
