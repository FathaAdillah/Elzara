<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpFoundation\Response;

final class VerifyAdminCsrf
{
    public function __construct(private readonly Encrypter $encrypter) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $token = $this->token($request);
        if (! $request->hasSession() || $token === null || ! hash_equals($request->session()->token(), $token)) {
            throw new TokenMismatchException('CSRF token mismatch.');
        }

        return $next($request);
    }

    private function token(Request $request): ?string
    {
        $headers = $request->headers->all('X-XSRF-TOKEN');
        $header = $headers[0] ?? null;
        if (count($headers) !== 1 || $header === null || strlen($header) > 1024) {
            return null;
        }

        try {
            $value = $this->encrypter->decrypt($header, EncryptCookies::serialized('XSRF-TOKEN'));
        } catch (DecryptException) {
            return null;
        }

        return is_string($value)
            ? CookieValuePrefix::validate('XSRF-TOKEN', $value, $this->encrypter->getAllKeys())
            : null;
    }
}
