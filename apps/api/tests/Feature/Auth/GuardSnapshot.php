<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class GuardSnapshot
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Test-Admin-Authenticated', Auth::guard('admin')->check() ? 'true' : 'false');
        $response->headers->set('X-Test-Session-Id', $request->session()->getId());

        return $response;
    }
}
