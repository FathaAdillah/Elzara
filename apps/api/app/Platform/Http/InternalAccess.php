<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class InternalAccess
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $cidrs */
        $cidrs = config('api.internal_cidrs', []);
        abort_unless(IpUtils::checkIp($request->ip() ?? '', $cidrs), 404);

        return $next($request);
    }
}
