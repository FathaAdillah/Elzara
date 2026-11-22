<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Catalog\Contracts\ProductSearchSource;
use App\Modules\Search\Infrastructure\ElasticsearchClient;
use App\Platform\Domain\Scalar;
use RuntimeException;

/**
 * Menyalin dokumen produk dari PostgreSQL (sumber kebenaran) ke indeks.
 * Versi eksternal = mikrodetik occurred_at, sehingga event lama yang datang terlambat tidak menimpa dokumen baru.
 */
final class ProductIndexer
{
    public function __construct(
        private readonly ElasticsearchClient $client,
        private readonly ProductIndex $index,
        private readonly ProductSearchSource $source,
    ) {}

    /** @param list<int> $productIds */
    public function sync(array $productIds, int $version, string $refresh = 'false'): void
    {
        $this->index->ensure();
        $documents = $this->source->documents($productIds);
        $lines = [];
        foreach ($productIds as $id) {
            $meta = ['_index' => $this->index->alias(), '_id' => (string) $id, 'version' => $version, 'version_type' => 'external_gte'];
            if (isset($documents[$id])) {
                $lines[] = ['index' => $meta];
                $lines[] = $documents[$id];
            } else {
                $lines[] = ['delete' => $meta];
            }
        }
        if ($lines !== []) {
            $this->assertBulk($this->client->bulk($lines, $refresh));
        }
    }

    /** @return array{index: string, documents: int} */
    public function reindexAll(int $version, int $chunk = 500): array
    {
        $index = $this->index->create();
        $count = 0;
        $this->source->eachProductIdChunk($chunk, function (array $ids) use ($index, $version, &$count): void {
            $lines = [];
            foreach ($this->source->documents($ids) as $id => $document) {
                $lines[] = ['index' => ['_index' => $index, '_id' => (string) $id, 'version' => $version, 'version_type' => 'external_gte']];
                $lines[] = $document;
                $count++;
            }
            if ($lines !== []) {
                $this->assertBulk($this->client->bulk($lines));
            }
        });
        $this->client->request('POST', $index.'/_refresh');
        $this->index->swap($index);

        return ['index' => $index, 'documents' => $count];
    }

    /** @param array<string, mixed> $response */
    private function assertBulk(array $response): void
    {
        if (($response['errors'] ?? false) !== true) {
            return;
        }
        $items = is_array($response['items'] ?? null) ? $response['items'] : [];
        foreach ($items as $item) {
            $result = is_array($item) ? (array) (reset($item) ?: []) : [];
            $status = Scalar::int($result['status'] ?? 200);
            // 409 = versi indeks sudah lebih baru (event basi); 404 = hapus dokumen yang memang belum ada.
            if ($status >= 300 && ! in_array($status, [404, 409], true)) {
                throw new RuntimeException('Bulk Elasticsearch gagal: '.mb_substr((string) json_encode($result), 0, 500));
            }
        }
    }
}
