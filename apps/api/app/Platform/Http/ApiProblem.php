<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stringable;

/** RFC 7807 + field tambahan Doc 12 §4.1; Content-Type selalu application/problem+json. */
final class ApiProblem implements Stringable
{
    private const CATEGORIES = [
        'validation', 'auth', 'authorization', 'not-found', 'inventory', 'batch', 'checkout', 'order',
        'payment', 'quotation', 'media', 'idempotency', 'pagination', 'rate-limit', 'conflict',
        'state', 'external', 'server',
    ];

    /** @param list<array{pointer: string, code: string, detail: string}> $errors */
    private function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly int $status,
        public readonly string $code,
        public readonly bool $retryable,
        public readonly string $detail = '',
        public readonly string $instance = '',
        public readonly string $requestId = '',
        public readonly array $errors = [],
    ) {
    }

    public static function make(
        string $category,
        string $kebabCode,
        string $title,
        int $status,
        string $code,
        bool $retryable = false,
        string $detail = '',
    ): self {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException("Kategori problem '{$category}' tidak ada di daftar tertutup Doc 12 §4.2.");
        }

        return new self("urn:elzara:problem:{$category}:{$kebabCode}", $title, $status, $code, $retryable, $detail);
    }

    public function forRequest(Request $request, string $requestId): self
    {
        return new self(
            $this->type, $this->title, $this->status, $this->code, $this->retryable,
            $this->detail, '/' . ltrim($request->path(), '/'), $requestId, $this->errors,
        );
    }

    /** @param array{pointer: string, code: string, detail: string} $error */
    public function withError(array $error): self
    {
        return new self(
            $this->type, $this->title, $this->status, $this->code, $this->retryable,
            $this->detail, $this->instance, $this->requestId, [...$this->errors, $error],
        );
    }

    public function withDetail(string $detail): self
    {
        return new self(
            $this->type, $this->title, $this->status, $this->code, $this->retryable,
            $detail, $this->instance, $this->requestId, $this->errors,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            'request_id' => $this->requestId,
            'code' => $this->code,
            'retryable' => $this->retryable,
        ];

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return $body;
    }

    public function toResponse(): JsonResponse
    {
        $headers = [
            'Content-Type' => 'application/problem+json',
        ];
        if ($this->requestId !== '') {
            $headers[AttachRequestId::HEADER] = $this->requestId;
        }

        return new JsonResponse($this->toArray(), $this->status, $headers);
    }

    public function __toString(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
