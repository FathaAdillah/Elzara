<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class SelectAdminSession
{
    public const AUTH_COOKIE = 'elzara_admin_session';

    public const CHALLENGE_COOKIE = 'elzara_admin_challenge';

    public function __construct(private readonly SessionManager $sessions) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $query = $request->query->all();
        $bootstrap = $request->is('sanctum/csrf-cookie') && ($query['scope'] ?? null) === 'admin';
        if (! $bootstrap && ! $request->is('api/v1/admin/*')) {
            return $next($request);
        }

        $preAuth = $request->is('api/v1/admin/auth/login', 'api/v1/admin/auth/2fa/verify')
            || ($bootstrap && (($query['purpose'] ?? null) === 'login' || ! $request->cookies->has(self::AUTH_COOKIE)));
        $previous = Config::array('session');
        $previousGuard = Auth::getDefaultDriver();
        $previousCookie = Config::string('session.cookie');
        $secure = config('app.env') !== 'local' || $previous['secure'] !== false;
        config(['session' => array_replace($previous, [
            'cookie' => $preAuth ? self::CHALLENGE_COOKIE : self::AUTH_COOKIE,
            'path' => '/',
            'domain' => null,
            'secure' => $secure,
            'block' => true,
            'http_only' => true,
            'same_site' => 'lax',
            'partitioned' => false,
        ])]);
        /** @var Store $store */
        $store = $this->sessions->driver();
        $store->flush();
        $store->setName(Config::string('session.cookie'));
        Auth::forgetGuards();

        try {
            $response = $next($request);
            $expired = $request->attributes->get('expire_admin_cookie');
            if (! $preAuth && ($bootstrap || ! $request->cookies->has(self::AUTH_COOKIE))) {
                $response->headers->removeCookie(self::AUTH_COOKIE, '/', null);
            }
            if (is_string($expired)) {
                $response->headers->setCookie(Cookie::create($expired, null, 1, '/', null, $secure, true, false, 'lax'));
            }

            return $response;
        } finally {
            $store->flush();
            $store->setName($previousCookie);
            Auth::forgetGuards();
            Auth::shouldUse($previousGuard);
            config(['session' => $previous]);
        }
    }
}
