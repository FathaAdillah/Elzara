<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/** W3C Trace Context: lanjutkan `traceparent` dari web/admin, atau mulai trace baru; job mewarisinya lewat Context. */
final class TraceContext
{
    public const HEADER = 'traceparent';

    private const PATTERN = '/\A00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})\z/';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->all(self::HEADER);
        $parent = count($incoming) === 1 && is_string($incoming[0]) ? self::parse($incoming[0]) : null;

        self::startSpan($parent['trace_id'] ?? null, $parent['span_id'] ?? null, $parent['flags'] ?? '01');

        $response = $next($request);
        $response->headers->set(self::HEADER, self::header());

        return $response;
    }

    /** @return array{trace_id: string, span_id: string, flags: string}|null */
    public static function parse(string $value): ?array
    {
        if (preg_match(self::PATTERN, strtolower(trim($value)), $m) !== 1) {
            return null;
        }

        if ($m[1] === str_repeat('0', 32) || $m[2] === str_repeat('0', 16)) {
            return null;
        }

        return ['trace_id' => $m[1], 'span_id' => $m[2], 'flags' => $m[3]];
    }

    /** Span baru di bawah trace aktif (atau trace baru); dipakai request masuk dan setiap job yang diproses worker. */
    public static function startSpan(?string $traceId = null, ?string $parentSpanId = null, string $flags = '01'): void
    {
        $traceId ??= self::id(16);
        Context::add('trace_id', $traceId);
        Context::add('span_id', self::id(8));
        Context::add('trace_flags', $flags);
        $parentSpanId === null ? Context::forget('parent_span_id') : Context::add('parent_span_id', $parentSpanId);
    }

    public static function header(): ?string
    {
        $traceId = Context::get('trace_id');
        $spanId = Context::get('span_id');
        $flags = Context::get('trace_flags', '01');

        return is_string($traceId) && is_string($spanId) && is_string($flags) ? "00-{$traceId}-{$spanId}-{$flags}" : null;
    }

    /** @param int<1, max> $bytes */
    private static function id(int $bytes): string
    {
        do {
            $id = bin2hex(random_bytes($bytes));
        } while (trim($id, '0') === '');

        return $id;
    }
}
