<?php

declare(strict_types=1);

namespace App\Platform\Http;

use App\Platform\Domain\Exception\IdempotencyKeyReuse;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as LaravelResponse;
use Illuminate\Support\Facades\DB;
use LogicException;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class IdempotencyStore
{
    private const HEADERS = ['Content-Type', 'Location', AttachRequestId::HEADER];

    /** @param Closure(): Response $effect */
    public function run(
        int $actor,
        string $operation,
        #[SensitiveParameter] string $key,
        string $hash,
        int $ttl,
        string $requestId,
        Closure $effect,
    ): ?Response {
        $db = DB::connection();
        $level = $db->transactionLevel();
        $db->beginTransaction();
        try {
            $scope = ['actor_id' => $actor, 'operation' => $operation, 'idempotency_key' => $key];
            $claim = [
                'request_hash' => $hash,
                'status' => 'in_progress',
                'expires_at' => now()->addSeconds($ttl),
            ];
            $inserted = $db->table('idempotency_keys')->insertOrIgnore($scope + $claim);
            $query = $db->table('idempotency_keys')->where($scope);

            /** @var object{request_hash: string, expires_at: string, response_status: int|null, response_body: string|null, response_headers: string|null} $row */
            $row = (clone $query)->lockForUpdate()->firstOrFail();
            if ($inserted === 0) {
                if (CarbonImmutable::parse($row->expires_at)->isFuture()) {
                    if (! hash_equals($row->request_hash, $hash)) {
                        throw new IdempotencyKeyReuse;
                    }
                    $replay = $this->replay($row->response_status, $row->response_body, $row->response_headers);
                    $db->commit();

                    return $replay;
                }

                $query->update($claim + [
                    'response_ref' => null,
                    'response_status' => null,
                    'response_body' => null,
                    'response_headers' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $db->beginTransaction();
            $response = $effect();
            $response->headers->set('Idempotency-Replayed', 'false');
            $status = $response->getStatusCode();
            $isException = ($response instanceof JsonResponse || $response instanceof LaravelResponse)
                && $response->exception instanceof Throwable;
            if ($status < 200 || $status >= 500 || ($isException && $status < 400)) {
                $db->rollBack($level);

                return $response;
            }
            if ($status >= 400) {
                // Simpan hasil 4xx, tetapi batalkan efek bisnis pada savepoint.
                $db->rollBack($level + 1);
            } else {
                $db->commit();
            }

            $body = $response->getContent();
            if ($body === false || ($body !== '' && ! json_validate($body))) {
                throw new LogicException('Respons idempotensi harus berisi JSON atau body kosong.');
            }

            $headers = [AttachRequestId::HEADER => $requestId];
            foreach (['Content-Type', 'Location'] as $name) {
                $value = $response->headers->get($name);
                if ($value !== null) {
                    $headers[$name] = $value;
                }
            }

            $query->update([
                'status' => $status < 400 ? 'succeeded' : 'failed',
                'response_status' => $status,
                'response_body' => $body,
                'response_headers' => json_encode((object) $headers, JSON_THROW_ON_ERROR),
                'expires_at' => now()->addSeconds($ttl),
                'updated_at' => now(),
            ]);
            $db->commit();

            return $response;
        } catch (Throwable $exception) {
            $db->rollBack($level);
            throw $exception;
        }
    }

    private function replay(?int $status, ?string $body, ?string $encodedHeaders): ?Response
    {
        if ($status === null || $body === null || $encodedHeaders === null) {
            return null;
        }

        $decoded = json_decode($encodedHeaders, true, 16, JSON_THROW_ON_ERROR);
        $headers = ['Idempotency-Replayed' => 'true'];
        foreach (self::HEADERS as $name) {
            if (is_array($decoded) && isset($decoded[$name]) && is_string($decoded[$name])) {
                $headers[$name] = $decoded[$name];
            }
        }

        return new Response($body, $status, $headers);
    }
}
