<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Ramsey\Uuid\Uuid;

final class DispatchDomainEvent implements ShouldQueue
{
    private const EVENT_ID_NAMESPACE = '6f1c0bd2-6d5b-4f1f-9b8a-2f8b9a5c1e07';

    public int $tries = 5;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $outboxId,
        public readonly string $eventName,
        public readonly string $aggregateType,
        public readonly int $aggregateId,
        public readonly array $payload = [],
    ) {
    }

    /** @return list<int> eksponensial + jitter (Doc 01 §10); setelah $tries habis job masuk failed_jobs sebagai DLQ */
    public function backoff(): array
    {
        return array_map(
            static fn (int $base): int => $base + random_int(0, intdiv($base, 4)),
            [5, 15, 45, 135, 405],
        );
    }

    public function handle(): void
    {
        Event::dispatch($this->eventName, [['event_id' => $this->eventId()] + $this->toArray()]);
    }

    public function eventId(): string
    {
        return Uuid::uuid5(self::EVENT_ID_NAMESPACE, (string) $this->outboxId)->toString();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'payload' => $this->payload,
        ];
    }
}
