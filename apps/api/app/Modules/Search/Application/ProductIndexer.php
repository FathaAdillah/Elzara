<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Catalog\Contracts\ProductSearchSource;
use App\Modules\Search\Infrastructure\ElasticsearchClient;
use App\Platform\Domain\Scalar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

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
        $this->replaySince($version);

        return ['index' => $index, 'documents' => $count];
    }

    /** Sync yang masuk ke alias lama selama rebuild hilang saat swap; proyeksikan ulang produknya ke indeks baru. */
    private function replaySince(int $version): void
    {
        DB::table('outbox_events')->where('event_name', 'ProductUpdated')->where('aggregate_type', 'product')
            ->whereRaw("(payload->>'occurred_at')::timestamptz >= to_timestamp(? / 1000000.0)", [$version])
            ->groupBy('aggregate_id')->orderBy('aggregate_id')
            ->selectRaw("aggregate_id, max((payload->>'occurred_at')::timestamptz) AS occurred_at")
            ->get()
            ->each(function (stdClass $row): void {
                $this->sync([Scalar::int($row->aggregate_id)], (int) Carbon::parse(Scalar::string($row->occurred_at))->format('Uu'));
            });
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
