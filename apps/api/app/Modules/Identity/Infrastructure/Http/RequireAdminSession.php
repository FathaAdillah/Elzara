<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminSession
{
    public function __construct(private readonly AdminSession $sessions) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('media_actor', $this->sessions->activeUser($request)->getKey());

        return $next($request);
    }
}
