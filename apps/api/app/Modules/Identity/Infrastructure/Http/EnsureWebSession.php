<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

// Callback OAuth datang lewat navigasi lintas situs tanpa Origin/Referer storefront, sehingga
// statefulApi Sanctum tidak membuka sesi. Middleware ini membukanya agar state OAuth terbaca.
final class EnsureWebSession
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            return $next($request);
        }

        /** @var Response */
        return app(Pipeline::class)
            ->send($request)
            ->through([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class])
            ->then($next);
    }
}
