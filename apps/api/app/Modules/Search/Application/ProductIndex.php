<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Search\Infrastructure\ElasticsearchClient;
use Illuminate\Support\Carbon;

/**
 * Indeks produk di balik alias: reindex membangun indeks baru lalu memindahkan alias secara atomik.
 * Analyzer: `elzara_text` (lowercase + asciifolding), `elzara_prefix` (edge n-gram 2–20) dan `elzara_search`
 * (sinonim eksplisit via synonym_graph, hanya saat query).
 */
final class ProductIndex
{
    public function __construct(private readonly ElasticsearchClient $client) {}

    public function alias(): string
    {
        $alias = config('search.products_alias');

        return is_string($alias) && $alias !== '' ? $alias : 'elzara_products';
    }

    public function ensure(): void
    {
        $exists = $this->client->request('HEAD', $this->alias(), allow: [404]);
        if ($exists['_status'] === 404) {
            $index = $this->create();
            $this->client->request('POST', '_aliases', ['actions' => [['add' => ['index' => $index, 'alias' => $this->alias(), 'is_write_index' => true]]]]);
        }
    }

    public function create(): string
    {
        $name = $this->alias().'_'.Carbon::now()->format('YmdHisu');
        $this->client->request('PUT', $name, self::definition());

        return $name;
    }

    /** @return list<string> */
    public function indicesBehindAlias(): array
    {
        $response = $this->client->request('GET', '_alias/'.$this->alias(), allow: [404]);
        unset($response['_status'], $response['error'], $response['status']);

        return array_map('strval', array_keys($response));
    }

    public function swap(string $index): void
    {
        $old = $this->indicesBehindAlias();
        $actions = [['add' => ['index' => $index, 'alias' => $this->alias(), 'is_write_index' => true]]];
        foreach ($old as $previous) {
            $actions[] = ['remove' => ['index' => $previous, 'alias' => $this->alias()]];
        }
        $this->client->request('POST', '_aliases', ['actions' => $actions]);
        foreach ($old as $previous) {
            $this->client->request('DELETE', $previous, allow: [404]);
        }
    }

    public function drop(): void
    {
        foreach ($this->indicesBehindAlias() as $index) {
            $this->client->request('DELETE', $index, allow: [404]);
        }
    }

    public function refresh(): void
    {
        $this->client->request('POST', $this->alias().'/_refresh');
    }

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        $synonyms = config('search.synonyms', []);
        $keyword = ['type' => 'keyword'];

        return [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0,
                'refresh_interval' => '1s',
                'max_ngram_diff' => 18,
                'analysis' => [
                    'filter' => [
                        'elzara_edge' => ['type' => 'edge_ngram', 'min_gram' => 2, 'max_gram' => 20],
                        'elzara_synonyms' => ['type' => 'synonym_graph', 'synonyms' => is_array($synonyms) ? array_values($synonyms) : [], 'lenient' => false],
                    ],
                    'analyzer' => [
                        'elzara_text' => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding']],
                        'elzara_search' => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding', 'elzara_synonyms']],
                        'elzara_prefix' => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding', 'elzara_edge']],
                    ],
                    'normalizer' => [
                        'elzara_keyword' => ['type' => 'custom', 'filter' => ['lowercase', 'asciifolding']],
                    ],
                ],
            ],
            'mappings' => [
                'dynamic' => 'strict',
                'properties' => [
                    'id' => ['type' => 'long'],
                    'slug' => $keyword,
                    'name' => ['type' => 'text', 'analyzer' => 'elzara_text', 'search_analyzer' => 'elzara_search', 'fields' => [
                        'prefix' => ['type' => 'text', 'analyzer' => 'elzara_prefix', 'search_analyzer' => 'elzara_text'],
                        'sort' => ['type' => 'keyword', 'normalizer' => 'elzara_keyword'],
                    ]],
                    'series' => ['type' => 'text', 'analyzer' => 'elzara_text', 'search_analyzer' => 'elzara_search'],
                    'description' => ['type' => 'text', 'analyzer' => 'elzara_text', 'search_analyzer' => 'elzara_search'],
                    'status' => $keyword,
                    'origin_country' => $keyword,
                    'fulfillment_modes' => $keyword,
                    'brand' => $keyword,
                    'brand_name' => ['type' => 'text', 'analyzer' => 'elzara_text', 'search_analyzer' => 'elzara_search', 'fields' => [
                        'prefix' => ['type' => 'text', 'analyzer' => 'elzara_prefix', 'search_analyzer' => 'elzara_text'],
                    ]],
                    'category_id' => ['type' => 'long'],
                    'category' => $keyword,
                    'category_name' => $keyword,
                    'collections' => $keyword,
                    'skus' => ['type' => 'keyword', 'normalizer' => 'elzara_keyword'],
                    'variant_labels' => ['type' => 'text', 'analyzer' => 'elzara_text', 'search_analyzer' => 'elzara_search'],
                    'price_min' => ['type' => 'long'],
                    'price_max' => ['type' => 'long'],
                    'currency_code' => $keyword,
                    'source_version' => ['type' => 'long'],
                    'updated_at' => ['type' => 'date'],
                ],
            ],
        ];
    }
}
