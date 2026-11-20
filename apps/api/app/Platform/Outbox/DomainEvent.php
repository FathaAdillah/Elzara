<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use InvalidArgumentException;

/** B3: event lintas modul hanya ID + timestamp, tidak pernah objek atau graph. */
final class DomainEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly string $eventName,
        public readonly string $aggregateType,
        public readonly int $aggregateId,
        public readonly array $payload = [],
    ) {
        if ($aggregateId <= 0 || $aggregateType === '') {
            throw new InvalidArgumentException('Event outbox wajib menunjuk agregat bertipe dan ber-id positif.');
        }

        array_walk_recursive(
            $payload,
            static function (mixed $value, string|int $key): void {
                if (is_object($value)) {
                    throw new InvalidArgumentException("Payload event memuat objek pada '{$key}' - melanggar B3.");
                }
            },
        );
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
