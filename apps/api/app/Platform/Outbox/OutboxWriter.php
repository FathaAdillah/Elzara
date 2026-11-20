<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class OutboxWriter
{
    public function __construct(private readonly ?string $connection = null) {}

    /** Sengaja tanpa listener afterCommit: INSERT ini harus ikut transaksi penyebabnya (B4). */
    public function record(DomainEvent $event): int
    {
        QueueRouting::for($event->eventName);

        return (int) $this->db()->table('outbox_events')->insertGetId([
            'event_name' => $event->eventName,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
        ]);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
