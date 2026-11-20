<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyGuard
{
    public function __construct(private readonly IdempotencyStore $store) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $operation, string $guard, string $ttl = '86400'): Response
    {
        $ttlSeconds = filter_var($ttl, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($operation === '' || strlen($operation) > 80 || $guard === '' || $ttlSeconds === false) {
            throw new InvalidArgumentException('Konfigurasi operasi idempotensi tidak valid.');
        }

        $identifier = Auth::guard($guard)->id();
        $actor = is_int($identifier) || is_string($identifier)
            ? filter_var($identifier, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($actor === false) {
            abort(401);
        }

        $keys = $request->headers->all('Idempotency-Key');
        $key = $keys[0] ?? null;
        if ($key === null) {
            return $this->problem($request, 400, 'key-required', 'IDEMPOTENCY_KEY_REQUIRED', 'Header Idempotency-Key wajib');
        }
        if (count($keys) !== 1 || ! preg_match('/\A(?:[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-7][0-9A-HJKMNP-TV-Z]{25})\z/i', $key)) {
            return $this->problem($request, 400, 'key-invalid', 'IDEMPOTENCY_KEY_INVALID', 'Format Idempotency-Key tidak valid');
        }
        $key = strlen($key) === 36 ? strtolower($key) : strtoupper($key);

        $content = $request->getContent();
        if (strlen($content) > 262144) {
            return $this->problem($request, 413, 'payload-too-large', 'IDEMPOTENCY_PAYLOAD_TOO_LARGE', 'Ukuran body melebihi batas');
        }
        if ($content !== '' && ! $request->isJson()) {
            return $this->problem($request, 415, 'unsupported-media-type', 'IDEMPOTENCY_UNSUPPORTED_MEDIA_TYPE', 'Body mutasi harus berformat JSON');
        }
        try {
            $body = $content === '' ? null : json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            $hash = hash('sha256', json_encode([
                $request->getMethod(),
                $request->getPathInfo(),
                $this->normalize($request->query->all()),
                $content !== '',
                $this->normalize($body),
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (JsonException) {
            return $this->problem($request, 400, 'invalid-json', 'IDEMPOTENCY_INVALID_JSON', 'Body JSON tidak valid');
        }

        try {
            $response = $this->store->run($actor, $operation, $key, $hash, $ttlSeconds, AttachRequestId::getOrAssign($request), fn () => $next($request));
        } catch (QueryException) {
            // A QueryException formats SQL bindings into its message.
            throw new RuntimeException('Penyimpanan idempotensi gagal.');
        }
        if ($response === null) {
            return $this->problem($request, 409, 'result-unavailable', 'IDEMPOTENCY_RESULT_UNAVAILABLE', 'Hasil operasi belum dapat diputar ulang');
        }
        if ($response->headers->get('Idempotency-Replayed') === 'true') {
            $original = $response->headers->get(AttachRequestId::HEADER);
            if ($original !== null) {
                AttachRequestId::restore($request, $original);
            }
        }

        return $response;
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);

            return (object) array_map($this->normalize(...), $properties);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($this->normalize(...), $value);
        }

        return $value;
    }

    private function problem(Request $request, int $status, string $slug, string $code, string $title): Response
    {
        return ApiProblem::make('idempotency', $slug, $title, $status, $code)
            ->forRequest($request, AttachRequestId::getOrAssign($request))->toResponse();
    }
}
