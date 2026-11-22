<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OutboxWriter
{
    public function __construct(private readonly ?string $connection = null) {}

    /** Sengaja tanpa listener afterCommit: INSERT ini harus ikut transaksi penyebabnya (B4). */
    public function record(DomainEvent $event): int
    {
        QueueRouting::for($event->eventName);
        $database = $this->db();
        if ($database->transactionLevel() === 0) {
            throw new LogicException('Event outbox harus ditulis dalam transaksi yang menyebabkan event.');
        }

        return (int) $database->table('outbox_events')->insertGetId([
            'event_name' => $event->eventName,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
            'request_id' => self::correlation('request_id'),
            'trace_id' => self::correlation('trace_id'),
        ]);
    }

    /** Korelasi request/trace penyebab ikut tersimpan supaya job, listener, dan log provider bisa dirunut balik (Doc 01 §12). */
    private static function correlation(string $key): ?string
    {
        $value = Context::get($key);

        return is_string($value) ? $value : null;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
