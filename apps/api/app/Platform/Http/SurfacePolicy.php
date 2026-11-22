<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\Request;

final class SurfacePolicy
{
    public static function isAdmin(Request $request): bool
    {
        return $request->is('api/v1/admin/*')
            || ($request->is('sanctum/csrf-cookie') && $request->query('scope') === 'admin');
    }

    public static function origin(mixed $value, bool $referer = false): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $referer || $origin === $value ? $origin : null;
    }

    public static function authority(string $origin): ?string
    {
        $parts = parse_url($origin);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $port = $parts['port'] ?? null;
        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;

        return $parts['host'].($port !== null && $port !== $defaultPort ? ':'.$port : '');
    }
}
