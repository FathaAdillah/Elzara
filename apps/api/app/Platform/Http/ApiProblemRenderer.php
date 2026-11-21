<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Domain\Exception\BatchQuotaExhausted;
use App\Platform\Domain\Exception\ConflictRetryExhausted;
use App\Platform\Domain\Exception\DomainException;
use App\Platform\Domain\Exception\IdempotencyKeyReuse;
use App\Platform\Domain\Exception\InsufficientStock;
use App\Platform\Domain\Exception\MediaValidationFailed;
use App\Platform\Domain\Exception\QuotationVersionStale;
use App\Platform\StateMachine\InvalidTransition;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiProblemRenderer
{
    private const HTTP = [
        400 => ['validation', 'bad-request', 'Permintaan tidak valid', 'BAD_REQUEST', false],
        401 => ['auth', 'unauthenticated', 'Butuh autentikasi', 'UNAUTHENTICATED', true],
        403 => ['authorization', 'forbidden', 'Akses ditolak', 'FORBIDDEN', false],
        404 => ['not-found', 'resource', 'Tidak ditemukan', 'NOT_FOUND', false],
        409 => ['conflict', 'generic', 'Konflik', 'CONFLICT', false],
        410 => ['not-found', 'resource', 'Sudah tidak berlaku', 'GONE', false],
        413 => ['validation', 'failed', 'Muatan terlalu besar', 'PAYLOAD_TOO_LARGE', false],
        419 => ['auth', 'csrf-mismatch', 'Token CSRF tidak valid', 'CSRF_TOKEN_MISMATCH', true],
        422 => ['validation', 'failed', 'Validasi gagal', 'VALIDATION_FAILED', false],
        429 => ['rate-limit', 'exceeded', 'Melewati batas', 'RATE_LIMITED', true],
        503 => ['server', 'checkout-disabled', 'Sedang pemeliharaan', 'CHECKOUT_DISABLED', true],
    ];

    private const DOMAIN = [
        BatchQuotaExhausted::class => [422, 'batch', 'quota-exhausted', 'Kuota batch habis', 'BATCH_QUOTA_EXHAUSTED', false],
        ConflictRetryExhausted::class => [409, 'conflict', 'retry-exhausted', 'Konflik, percobaan ulang habis', 'CONFLICT_RETRY_EXHAUSTED', true],
        IdempotencyKeyReuse::class => [409, 'idempotency', 'key-payload-mismatch', 'Key idempotensi dipakai untuk payload berbeda', 'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH', false],
        InsufficientStock::class => [422, 'inventory', 'insufficient-stock', 'Stok tidak mencukupi', 'INSUFFICIENT_STOCK', false],
        MediaValidationFailed::class => [422, 'media', 'validation-failed', 'Berkas media ditolak', 'MEDIA_VALIDATION_FAILED', false],
        QuotationVersionStale::class => [409, 'quotation', 'version-stale', 'Versi kuotasi tidak terkini', 'QUOTATION_VERSION_STALE', false],
    ];

    public function render(Throwable $exception, Request $request): ?Response
    {
        if (! AttachRequestId::appliesTo($request)) {
            return null;
        }

        $requestId = AttachRequestId::getOrAssign($request);
        $status = $this->status($exception);
        /** @var array<string, list<string>|string|null> $headers */
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        if ($exception instanceof HttpResponseException) {
            $response = $exception->getResponse();
            if ($response->headers->get('Content-Type') === 'application/problem+json') {
                return $response;
            }
            if (! $response->isClientError() && ! $response->isServerError()) {
                $response->headers->set(AttachRequestId::HEADER, $requestId);

                return $response;
            }

            $headers = $response->headers->all();
        }

        $response = $this->problem($exception, $status)->forRequest($request, $requestId)->toResponse();
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), ['allow', 'retry-after', 'www-authenticate'], true)) {
                $response->headers->set($name, is_array($value) ? array_values(array_filter($value, is_string(...))) : (string) $value);
            }
        }

        return $response;
    }

    private function status(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof AuthenticationException => 401,
            $exception instanceof ValidationException => $exception->status,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof HttpResponseException => $exception->getResponse()->getStatusCode(),
            default => 500,
        };
    }

    private function problem(Throwable $exception, int $status): ApiProblem
    {
        if ($exception instanceof InvalidTransition) {
            return $exception->toProblem();
        }

        if ($exception instanceof DomainException && isset(self::DOMAIN[$exception::class])) {
            [$status, $category, $slug, $title, $code, $retryable] = self::DOMAIN[$exception::class];
            $problem = ApiProblem::make($category, $slug, $title, $status, $code, $retryable, $exception->getMessage());
            foreach ($exception->fieldErrors as $error) {
                $problem = $problem->withError($error);
            }

            return $problem;
        }

        [$category, $slug, $title, $code, $retryable] = self::HTTP[$status] ?? ($status >= 500
            ? ['server', 'internal', 'Terjadi kesalahan', 'INTERNAL', true]
            : ['validation', 'bad-request', 'Permintaan tidak dapat diproses', 'REQUEST_FAILED', false]);

        $problem = ApiProblem::make($category, $slug, $title, $status, $code, $retryable);
        if ($exception instanceof ValidationException) {
            /** @var array<array-key, list<string>> $errors */
            $errors = $exception->errors();
            foreach ($errors as $attribute => $messages) {
                $segments = explode('.', (string) $attribute);
                $pointer = '/'.implode('/', str_replace(['~', '/'], ['~0', '~1'], $segments));
                foreach ($messages as $message) {
                    $problem = $problem->withError([
                        'pointer' => $pointer,
                        'code' => 'invalid',
                        'detail' => $message,
                    ]);
                }
            }
        }

        return $problem;
    }
}
