<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure;

use App\Modules\Search\Application\SearchUnavailable;
use App\Platform\Domain\Scalar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Klien REST tipis untuk Elasticsearch Basic; kredensial aplikasi hanya API key terbatas (Doc 01 §11). */
final class ElasticsearchClient
{
    public function configured(): bool
    {
        $url = config('search.url');

        return is_string($url) && $url !== '';
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, string|int>  $query
     * @param  list<int>  $allow
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], array $allow = []): array
    {
        $response = $this->send($method, $path, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), 'application/json', $query);

        return $this->decode($response, $allow);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function bulk(array $lines, string $refresh = 'false'): array
    {
        $payload = implode("\n", array_map(static fn (array $line): string => json_encode($line, JSON_THROW_ON_ERROR), $lines))."\n";

        return $this->decode($this->send('POST', '_bulk', $payload, 'application/x-ndjson', ['refresh' => $refresh]), []);
    }

    /** @param array<string, string|int> $query */
    private function send(string $method, string $path, ?string $payload, string $contentType, array $query): Response
    {
        if (! $this->configured()) {
            throw new RuntimeException('ELASTICSEARCH_URL belum diatur.');
        }
        $url = rtrim(Scalar::string(config('search.url')), '/').'/'.ltrim($path, '/');
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        try {
            return $this->http()->withBody($payload ?? '', $contentType)->send($method, $url);
        } catch (ConnectionException $exception) {
            throw new SearchUnavailable('Elasticsearch tidak terjangkau.', previous: $exception);
        }
    }

    /**
     * @param  list<int>  $allow
     * @return array<string, mixed>
     */
    private function decode(Response $response, array $allow): array
    {
        if ($response->serverError() && ! in_array($response->status(), $allow, true)) {
            throw new SearchUnavailable("Elasticsearch {$response->status()}: ".mb_substr($response->body(), 0, 500));
        }
        if ($response->failed() && ! in_array($response->status(), $allow, true)) {
            throw new RuntimeException("Elasticsearch {$response->status()}: ".mb_substr($response->body(), 0, 500));
        }
        $json = $response->json();

        return ['_status' => $response->status(), ...(is_array($json) ? Scalar::fields((object) $json) : [])];
    }

    private function http(): PendingRequest
    {
        $request = Http::acceptJson()->timeout(max(1, Scalar::int(config('search.timeout', 5))));
        $key = config('search.api_key');

        return is_string($key) && $key !== '' ? $request->withHeaders(['Authorization' => 'ApiKey '.$key]) : $request;
    }
}
