<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use App\Platform\Outbox\OutboxDispatcher;
use App\Platform\Outbox\QueueRouting;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/** Format eksposisi Prometheus: registry Redis + gauge yang dihitung saat scrape (antrian, outbox, Postgres, Redis). */
final class MetricsExporter
{
    /** @var array<string, array{type: string, help: string, samples: list<array{0: string, 1: array<string, string>, 2: float}>}> */
    private array $families = [];

    public function render(): string
    {
        $this->families = [];
        $this->gauge('elzara_up', 'Exporter API hidup.', [], 1);

        $this->collect('registry', fn () => $this->registry());
        $this->collect('queues', fn () => $this->queues());
        $this->collect('failed_jobs', fn () => $this->failedJobs());
        $this->collect('outbox', fn () => $this->outbox());
        $this->collect('postgres', fn () => $this->postgres());
        $this->collect('redis', fn () => $this->redis());

        $out = '';
        foreach ($this->families as $name => $family) {
            $out .= "# HELP {$name} {$family['help']}\n# TYPE {$name} {$family['type']}\n";
            foreach ($family['samples'] as [$sample, $labels, $value]) {
                $out .= $sample.self::labels($labels).' '.Metrics::number($value)."\n";
            }
        }

        return $out;
    }

    private function registry(): void
    {
        $snapshot = Metrics::snapshot();

        foreach (Metrics::DEFINITIONS as $name => $definition) {
            $this->family($name, $definition['type'], $definition['help']);
            if ($definition['type'] === 'counter' && $definition['labels'] === []) {
                $this->sample($name, $name, [], 0);
            }
        }

        foreach ($snapshot['counters'] as $field => $value) {
            [$name, $labels] = self::decode($field);
            if (isset(Metrics::DEFINITIONS[$name])) {
                $this->sample($name, $name, $labels, (float) $value, replace: true);
            }
        }

        $series = [];
        foreach ($snapshot['histograms'] as $field => $value) {
            $parts = explode("\x1F", $field);
            if (count($parts) !== 3 || ! isset(Metrics::DEFINITIONS[$parts[0]])) {
                continue;
            }
            $series[$parts[0]."\x1F".$parts[1]][$parts[2]] = (float) $value;
        }

        foreach ($series as $key => $points) {
            [$name, $labels] = self::decode($key);
            foreach (Metrics::DEFINITIONS[$name]['buckets'] ?? [] as $le) {
                $this->sample($name, $name.'_bucket', $labels + ['le' => Metrics::number($le)], $points[Metrics::number($le)] ?? 0);
            }
            $count = $points['+Inf'] ?? 0;
            $this->sample($name, $name.'_bucket', $labels + ['le' => '+Inf'], $count);
            $this->sample($name, $name.'_sum', $labels, $points['sum'] ?? 0);
            $this->sample($name, $name.'_count', $labels, $count);
        }
    }

    private function queues(): void
    {
        $queue = Queue::connection('redis');
        if (! $queue instanceof RedisQueue) {
            return;
        }

        foreach (QueueRouting::QUEUES as $name) {
            $this->gauge('elzara_queue_jobs', 'Job di antrian per status.', ['queue' => $name, 'state' => 'pending'], (float) $queue->pendingSize($name));
            $this->gauge('elzara_queue_jobs', 'Job di antrian per status.', ['queue' => $name, 'state' => 'delayed'], (float) $queue->delayedSize($name));
            $this->gauge('elzara_queue_jobs', 'Job di antrian per status.', ['queue' => $name, 'state' => 'reserved'], (float) $queue->reservedSize($name));

            $oldest = $queue->creationTimeOfOldestPendingJob($name);
            $this->gauge('elzara_queue_lag_seconds', 'Umur job pending tertua (queue lag).', ['queue' => $name], $oldest === null ? 0 : max(0, time() - (int) $oldest));
        }
    }

    private function failedJobs(): void
    {
        $rows = DB::table('failed_jobs')->selectRaw('queue, count(*) AS total')->groupBy('queue')->get();
        $counts = array_fill_keys(QueueRouting::QUEUES, 0);
        foreach ($rows as $row) {
            if (isset($row->queue) && is_string($row->queue)) {
                $counts[$row->queue] = (int) self::field($row, 'total');
            }
        }
        foreach ($counts as $queue => $total) {
            $this->gauge('elzara_queue_dlq_jobs', 'Job di failed_jobs (DLQ) per antrian.', ['queue' => (string) $queue], $total);
        }
    }

    private function outbox(): void
    {
        $row = DB::selectOne(
            'SELECT count(*) FILTER (WHERE attempts < ?) AS pending, count(*) FILTER (WHERE attempts >= ?) AS dead, '
            .'coalesce(extract(epoch FROM now() - min(occurred_at) FILTER (WHERE attempts < ?)), 0) AS oldest '
            .'FROM outbox_events WHERE dispatched_at IS NULL',
            [OutboxDispatcher::MAX_ATTEMPTS, OutboxDispatcher::MAX_ATTEMPTS, OutboxDispatcher::MAX_ATTEMPTS],
        );
        $this->gauge('elzara_outbox_pending_events', 'Event outbox belum terkirim.', [], self::field($row, 'pending', 0));
        $this->gauge('elzara_outbox_dead_events', 'Event outbox yang kehabisan percobaan.', [], self::field($row, 'dead', 0));
        $this->gauge('elzara_outbox_oldest_pending_seconds', 'Umur event outbox pending tertua.', [], self::field($row, 'oldest', 0));
    }

    private function postgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $locks = DB::selectOne('SELECT count(*) AS waiting FROM pg_locks WHERE NOT granted');
        $this->gauge('elzara_pg_lock_waits', 'Lock Postgres yang sedang menunggu.', [], self::field($locks, 'waiting', 0));

        $db = DB::selectOne('SELECT deadlocks, numbackends FROM pg_stat_database WHERE datname = current_database()');
        $this->family('elzara_pg_deadlocks_total', 'counter', 'Deadlock Postgres sejak statistik direset.');
        $this->sample('elzara_pg_deadlocks_total', 'elzara_pg_deadlocks_total', [], self::field($db, 'deadlocks', 0));
        $this->gauge('elzara_pg_connections', 'Koneksi aktif ke database aplikasi.', [], self::field($db, 'numbackends', 0));
        $this->gauge('elzara_pg_max_connections', 'Batas max_connections Postgres.', [], self::field(DB::selectOne("SELECT current_setting('max_connections')::int AS max"), 'max'));

        $archiver = DB::selectOne(
            'SELECT archived_count, failed_count, coalesce(extract(epoch FROM now() - last_archived_time), -1) AS last_archived_age FROM pg_stat_archiver'
        );
        $this->family('elzara_pg_wal_archived_total', 'counter', 'Segmen WAL yang berhasil diarsipkan off-node.');
        $this->sample('elzara_pg_wal_archived_total', 'elzara_pg_wal_archived_total', [], self::field($archiver, 'archived_count', 0));
        $this->family('elzara_pg_wal_archive_failures_total', 'counter', 'Kegagalan archive_command (WAL tertahan di node).');
        $this->sample('elzara_pg_wal_archive_failures_total', 'elzara_pg_wal_archive_failures_total', [], self::field($archiver, 'failed_count', 0));
        $this->gauge('elzara_pg_wal_last_archived_age_seconds', 'Umur arsip WAL terakhir; -1 bila belum pernah (RPO efektif).', [], self::field($archiver, 'last_archived_age', -1));
    }

    private function redis(): void
    {
        $info = Redis::connection()->command('info', ['memory']);
        $memory = is_array($info) ? ($info['used_memory'] ?? (is_array($info['Memory'] ?? null) ? ($info['Memory']['used_memory'] ?? null) : null)) : null;
        if (is_numeric($memory)) {
            $this->gauge('elzara_redis_used_memory_bytes', 'Memori Redis terpakai.', [], (float) $memory);
        }
    }

    private function collect(string $collector, callable $collect): void
    {
        $ok = 1;
        try {
            $collect();
        } catch (Throwable) {
            $ok = 0;
        }
        $this->gauge('elzara_metrics_collector_up', 'Collector metrik berhasil (1) atau gagal (0) pada scrape ini.', ['collector' => $collector], $ok);
    }

    /** @param array<string, string> $labels */
    private function gauge(string $name, string $help, array $labels, float|int $value): void
    {
        $this->family($name, 'gauge', $help);
        $this->sample($name, $name, $labels, (float) $value);
    }

    private function family(string $name, string $type, string $help): void
    {
        $this->families[$name] ??= ['type' => $type, 'help' => $help, 'samples' => []];
    }

    /** @param array<string, string> $labels */
    private function sample(string $family, string $sample, array $labels, float $value, bool $replace = false): void
    {
        if ($replace) {
            $this->families[$family]['samples'] = array_values(array_filter(
                $this->families[$family]['samples'],
                static fn (array $s): bool => ! ($s[0] === $sample && $s[1] === $labels),
            ));
        }
        $this->families[$family]['samples'][] = [$sample, $labels, $value];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function decode(string $field): array
    {
        [$name, $json] = explode("\x1F", $field, 2) + [1 => '{}'];
        $decoded = json_decode($json, true);
        $labels = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $labels[$key] = (string) $value;
            }
        }

        return [$name, $labels];
    }

    private static function field(mixed $row, string $property, float $default = 0): float
    {
        $value = is_object($row) ? ($row->{$property} ?? null) : null;

        return is_numeric($value) ? (float) $value : $default;
    }

    /** @param array<string, string> $labels */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key.'="'.addcslashes($value, "\\\"\n").'"';
        }

        return '{'.implode(',', $pairs).'}';
    }
}
