<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Search\Infrastructure\ElasticsearchClient;
use App\Platform\Domain\Scalar;
use Illuminate\Validation\ValidationException;

/** multi_match + fuzziness, prefiks edge n-gram, sinonim eksplisit, filter dan facet keyword. */
final class ProductSearch
{
    public const FACETS = ['brand', 'category', 'collections', 'origin_country', 'fulfillment_modes', 'status'];

    public function __construct(private readonly ElasticsearchClient $client, private readonly ProductIndex $index) {}

    /**
     * @param  array{q?: string, brand?: list<string>, category?: list<string>, collections?: list<string>, origin_country?: list<string>, fulfillment_modes?: list<string>, status?: list<string>, price_min?: int, price_max?: int, limit?: int, cursor?: string}  $params
     * @return array{data: list<array<string, mixed>>, facets: array<string, list<array{value: string, count: int}>>, meta: array{limit: int, total: int, has_more: bool, next_cursor: ?string}}
     */
    public function search(array $params): array
    {
        $limit = $params['limit'] ?? 20;
        $filters = [];
        foreach (self::FACETS as $field) {
            if (($params[$field] ?? []) !== []) {
                $filters[$field] = ['terms' => [$field => $params[$field]]];
            }
        }
        $range = array_filter(['gte' => $params['price_min'] ?? null, 'lte' => $params['price_max'] ?? null], static fn (?int $value): bool => $value !== null);
        $q = trim($params['q'] ?? '');
        $must = $q === '' ? [['match_all' => (object) []]] : [[
            'bool' => ['should' => [
                ['multi_match' => ['query' => $q, 'type' => 'best_fields', 'fuzziness' => 'AUTO', 'prefix_length' => 1, 'operator' => 'and',
                    'fields' => ['name^4', 'brand_name^2', 'series^2', 'variant_labels', 'description']]],
                ['multi_match' => ['query' => $q, 'type' => 'bool_prefix', 'fields' => ['name.prefix^2', 'brand_name.prefix']]],
                ['term' => ['skus' => ['value' => mb_strtolower($q), 'boost' => 10]]],
            ], 'minimum_should_match' => 1],
        ]];
        $postFilter = array_values($filters);
        if ($range !== []) {
            $postFilter[] = ['range' => ['price_min' => $range]];
        }
        $aggregations = [];
        foreach (self::FACETS as $field) {
            $others = array_values(array_diff_key($filters, [$field => true]));
            if ($range !== []) {
                $others[] = ['range' => ['price_min' => $range]];
            }
            $aggregations[$field] = ['filter' => ['bool' => ['filter' => $others]], 'aggs' => ['values' => ['terms' => ['field' => $field, 'size' => 50]]]];
        }
        $body = [
            'size' => $limit + 1,
            'track_total_hits' => true,
            'query' => ['bool' => ['must' => $must]],
            'post_filter' => ['bool' => ['filter' => $postFilter]],
            'aggs' => $aggregations,
            'sort' => [['_score' => 'desc'], ['id' => 'asc']],
            '_source' => ['id', 'slug', 'name', 'brand', 'brand_name', 'category', 'collections', 'status', 'origin_country', 'fulfillment_modes', 'skus', 'price_min', 'price_max', 'currency_code', 'updated_at'],
        ];
        if (isset($params['cursor'])) {
            $body['search_after'] = self::decodeCursor($params['cursor']);
        }
        $this->index->ensure();
        $response = $this->client->request('POST', $this->index->alias().'/_search', $body);
        $hitsBlock = is_array($response['hits'] ?? null) ? $response['hits'] : [];
        $hits = is_array($hitsBlock['hits'] ?? null) ? array_values($hitsBlock['hits']) : [];
        $hasMore = count($hits) > $limit;
        $hits = array_slice($hits, 0, $limit);
        $last = $hits === [] ? null : end($hits);

        return [
            'data' => array_map(static fn (mixed $hit): array => is_array($hit) && is_array($hit['_source'] ?? null) ? Scalar::fields((object) $hit['_source']) : [], $hits),
            'facets' => self::facets(is_array($response['aggregations'] ?? null) ? $response['aggregations'] : []),
            'meta' => [
                'limit' => $limit,
                'total' => Scalar::int(is_array($hitsBlock['total'] ?? null) ? ($hitsBlock['total']['value'] ?? 0) : 0),
                'has_more' => $hasMore,
                'next_cursor' => $hasMore && is_array($last) && is_array($last['sort'] ?? null) ? self::encodeCursor($last['sort']) : null,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $aggregations
     * @return array<string, list<array{value: string, count: int}>>
     */
    private static function facets(array $aggregations): array
    {
        $facets = [];
        foreach (self::FACETS as $field) {
            $facet = is_array($aggregations[$field] ?? null) ? $aggregations[$field] : [];
            $values = is_array($facet['values'] ?? null) ? $facet['values'] : [];
            $buckets = $values['buckets'] ?? [];
            $facets[$field] = array_values(array_map(static fn (mixed $bucket): array => [
                'value' => is_array($bucket) ? Scalar::string($bucket['key'] ?? '') : '',
                'count' => is_array($bucket) ? Scalar::int($bucket['doc_count'] ?? 0) : 0,
            ], is_array($buckets) ? $buckets : []));
        }

        return $facets;
    }

    /** @param array<array-key, mixed> $sort */
    private static function encodeCursor(array $sort): string
    {
        $payload = json_encode(array_values($sort), JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload.'.'.hash_hmac('sha256', $payload, Scalar::string(config('app.key')))), '+/', '-_'), '=');
    }

    /** @return list<mixed> */
    private static function decodeCursor(string $cursor): array
    {
        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        $position = $raw === false ? false : strrpos($raw, '.');
        if ($raw !== false && $position !== false) {
            $payload = substr($raw, 0, $position);
            if (hash_equals(hash_hmac('sha256', $payload, Scalar::string(config('app.key'))), substr($raw, $position + 1))) {
                $sort = json_decode($payload, true);
                if (is_array($sort) && count($sort) === 2) {
                    return array_values($sort);
                }
            }
        }

        throw ValidationException::withMessages(['cursor' => 'Cursor tidak valid.']);
    }
}
