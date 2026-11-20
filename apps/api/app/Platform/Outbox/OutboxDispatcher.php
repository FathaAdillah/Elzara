<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** Klaim lalu serahkan ke Horizon; dispatched_at berarti broker sudah menerima, sisanya ditanggung dedup inbox. */
final class OutboxDispatcher
{
    public const MAX_ATTEMPTS = 5;

    private const LEASE_EXPIRED = "CURRENT_TIMESTAMP - INTERVAL '2 minutes'";

    public function __construct(
        private readonly ?string $connection = null,
        private readonly int $maxAttempts = self::MAX_ATTEMPTS,
    ) {}

    /** @return array{claimed: int, dispatched: int, failed: int, dead: int} */
    public function dispatchBatch(int $limit = 50, ?string $onlyQueue = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Batas batch outbox harus bilangan positif.');
        }

        if ($onlyQueue !== null && ! QueueRouting::isQueue($onlyQueue)) {
            throw new InvalidArgumentException("Antrian '{$onlyQueue}' bukan salah satu dari tujuh nama kanonik (Doc 11 §8.1).");
        }

        if ($this->db()->transactionLevel() !== 0) {
            throw new LogicException('Dispatcher outbox harus meng-commit klaim sebelum mengirim ke antrian.');
        }

        $worker = (string) Str::ulid();
        $claimed = $this->claim($limit, $onlyQueue, $worker);
        $dispatched = 0;
        $failed = 0;

        foreach ($claimed as $row) {
            $id = (int) $row->id;
            if ($this->ownedEvent($id, $worker)->update(['locked_at' => $this->db()->raw('CURRENT_TIMESTAMP')]) !== 1) {
                continue;
            }

            try {
                $queue = Queue::connection();
                if ($queue instanceof NullQueue) {
                    throw new RuntimeException('Koneksi null membuang job outbox.');
                }

                /** @var array<string, mixed> $payload */
                $payload = json_decode($row->payload, true, 8, JSON_THROW_ON_ERROR);
                $job = new DispatchDomainEvent(
                    outboxId: $id,
                    eventName: $row->event_name,
                    aggregateType: $row->aggregate_type,
                    aggregateId: (int) $row->aggregate_id,
                    payload: $payload,
                );
                $queue->push($job, queue: QueueRouting::for($job->eventName));
            } catch (Throwable $e) {
                $failed += $this->ownedEvent($id, $worker)->update([
                    'last_error' => Str::limit($e->getMessage(), 500, ''),
                    'locked_by' => null,
                    'locked_at' => null,
                ]);

                continue;
            }

            $dispatched += $this->ownedEvent($id, $worker)->update([
                'dispatched_at' => $this->db()->raw('CURRENT_TIMESTAMP'),
                'locked_by' => null,
                'locked_at' => null,
                'last_error' => null,
            ]);
        }

        return [
            'claimed' => $claimed->count(),
            'dispatched' => $dispatched,
            'failed' => $failed,
            'dead' => $this->deadCount($onlyQueue),
        ];
    }

    /** @return Collection<int, object{id: int|numeric-string, event_name: string, aggregate_type: string, aggregate_id: int|numeric-string, payload: string}> */
    private function claim(int $limit, ?string $onlyQueue, string $worker): Collection
    {
        return $this->db()->transaction(function () use ($limit, $onlyQueue, $worker): Collection {
            $query = $this->availableEvents($onlyQueue)
                ->where('attempts', '<', $this->maxAttempts);

            /** @var Collection<int, object{id: int|numeric-string, event_name: string, aggregate_type: string, aggregate_id: int|numeric-string, payload: string}> $rows */
            $rows = $query->orderBy('occurred_at')->orderBy('id')->lock('for update skip locked')->limit($limit)->get();

            if ($rows->isEmpty()) {
                return $rows;
            }

            $this->db()->table('outbox_events')->whereIn('id', $rows->pluck('id'))
                ->update([
                    'locked_by' => $worker,
                    'locked_at' => $this->db()->raw('CURRENT_TIMESTAMP'),
                    'attempts' => $this->db()->raw('attempts + 1'),
                ]);

            return $rows;
        });
    }

    /** Dead-letter adalah predikat, bukan kolom: Doc 09 §17 tidak memberi outbox_events.status. */
    private function deadCount(?string $onlyQueue): int
    {
        return $this->availableEvents($onlyQueue)->where('attempts', '>=', $this->maxAttempts)->count();
    }

    private function availableEvents(?string $onlyQueue): Builder
    {
        $query = $this->db()->table('outbox_events')
            ->whereNull('dispatched_at')
            ->where(function (Builder $query): void {
                $query->whereNull('locked_at')->orWhere('locked_at', '<=', $this->db()->raw(self::LEASE_EXPIRED));
            });

        if ($onlyQueue !== null) {
            $query->whereIn('event_name', QueueRouting::byQueue()[$onlyQueue]);
        }

        return $query;
    }

    private function ownedEvent(int $id, string $worker): Builder
    {
        return $this->db()->table('outbox_events')
            ->where('id', $id)
            ->where('locked_by', $worker)
            ->whereNull('dispatched_at');
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
