<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use App\Modules\Search\Infrastructure\ElasticsearchClient;
use App\Platform\Domain\Scalar;
use App\Platform\Outbox\InboxConsumer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Konsumen `ProductUpdated` di antrean imports: dedup inbox per event, lalu proyeksikan ulang produk dari PostgreSQL. */
final class SyncProductSearchIndex
{
    public const CONSUMER = 'search.product-index';

    public function __construct(
        private readonly InboxConsumer $inbox,
        private readonly ProductIndexer $indexer,
        private readonly ElasticsearchClient $client,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): void
    {
        if (! $this->client->configured()) {
            Log::warning('search.product_index.skipped', ['reason' => 'ELASTICSEARCH_URL kosong']);

            return;
        }
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $productId = Scalar::int($event['aggregate_id'] ?? 0);
        $occurred = is_string($payload['occurred_at'] ?? null) ? Carbon::parse($payload['occurred_at']) : Carbon::now();
        $version = (int) $occurred->format('Uu');
        $this->inbox->run(self::CONSUMER, Scalar::string($event['event_id'] ?? ''), $event, function () use ($productId, $version): void {
            $this->indexer->sync([$productId], $version);
        });
    }
}
