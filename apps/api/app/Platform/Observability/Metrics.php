<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Throwable;

/**
 * Registry metrik Prometheus berbasis Redis supaya php-fpm, worker, dan scheduler berbagi angka (Doc 01 §12).
 * Hanya nama di DEFINITIONS yang diterima: label bebas di luar daftar ini membuat kardinalitas meledak.
 * Gagal tulis ditelan: metrik tidak boleh menggagalkan request atau job.
 */
final class Metrics
{
    public const COUNTERS_KEY = 'observability:metrics:counters';

    public const HISTOGRAMS_KEY = 'observability:metrics:histograms';

    private const LATENCY = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private const DELAY = [1.0, 5.0, 15.0, 30.0, 60.0, 120.0, 300.0, 900.0, 1800.0, 3600.0];

    /** @var array<string, array{type: 'counter'|'histogram', help: string, labels: list<string>, buckets?: list<float>}> */
    public const DEFINITIONS = [
        'elzara_http_requests_total' => ['type' => 'counter', 'help' => 'Request HTTP API per route template dan status.', 'labels' => ['method', 'route', 'status']],
        'elzara_http_request_duration_seconds' => ['type' => 'histogram', 'help' => 'Latensi request HTTP API per route template.', 'labels' => ['method', 'route'], 'buckets' => self::LATENCY],
        'elzara_queue_jobs_total' => ['type' => 'counter', 'help' => 'Job Horizon selesai per antrian dan hasil.', 'labels' => ['queue', 'outcome']],
        'elzara_queue_job_retries_total' => ['type' => 'counter', 'help' => 'Percobaan ulang job (attempt > 1).', 'labels' => ['queue']],
        'elzara_queue_job_wait_seconds' => ['type' => 'histogram', 'help' => 'Jeda antara job dibuat dan mulai diproses.', 'labels' => ['queue'], 'buckets' => self::DELAY],
        'elzara_queue_job_duration_seconds' => ['type' => 'histogram', 'help' => 'Durasi eksekusi job.', 'labels' => ['queue'], 'buckets' => self::LATENCY],
        'elzara_storage_errors_total' => ['type' => 'counter', 'help' => 'Error object storage (MinIO/S3) yang dilaporkan aplikasi.', 'labels' => ['exception']],
        'elzara_oversell_anomalies_total' => ['type' => 'counter', 'help' => 'Anomali oversell terdeteksi (harus selalu 0).', 'labels' => []],
        'elzara_paid_stock_exceptions_total' => ['type' => 'counter', 'help' => 'Order lunas tanpa stok yang bisa dipenuhi.', 'labels' => []],
        'elzara_rbac_bypass_total' => ['type' => 'counter', 'help' => 'Pemakaian jalur bypass RBAC (break-glass).', 'labels' => []],
        'elzara_reservations_total' => ['type' => 'counter', 'help' => 'Reservasi stok per hasil (expired/converted/released).', 'labels' => ['outcome']],
        'elzara_payment_intents_total' => ['type' => 'counter', 'help' => 'Payment intent per hasil.', 'labels' => ['provider', 'outcome']],
        'elzara_webhook_delay_seconds' => ['type' => 'histogram', 'help' => 'Jeda event provider sampai webhook diterima.', 'labels' => ['provider'], 'buckets' => self::DELAY],
        'elzara_waiting_room_admissions_total' => ['type' => 'counter', 'help' => 'Admisi Waiting Room (bila diaktifkan).', 'labels' => []],
        'elzara_waiting_room_wait_seconds' => ['type' => 'histogram', 'help' => 'Lama tunggu Waiting Room.', 'labels' => [], 'buckets' => self::DELAY],
    ];

    private const HISTOGRAM_SCRIPT = <<<'LUA'
        for i = 1, #ARGV, 2 do
            redis.call('HINCRBYFLOAT', KEYS[1], ARGV[i], ARGV[i + 1])
        end
        return 1
        LUA;

    /** @param array<string, string> $labels */
    public static function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $field = self::field($name, 'counter', $labels);
        self::write(static fn (PhpRedisConnection $redis): mixed => $redis->command('hincrbyfloat', [self::COUNTERS_KEY, $field, $by]));
    }

    /** @param array<string, string> $labels */
    public static function observe(string $name, float $value, array $labels = []): void
    {
        $base = self::field($name, 'histogram', $labels);
        $arguments = [];
        foreach (self::DEFINITIONS[$name]['buckets'] ?? [] as $le) {
            if ($value <= $le) {
                array_push($arguments, $base."\x1F".self::number($le), '1');
            }
        }
        array_push($arguments, $base."\x1F+Inf", '1', $base."\x1Fsum", self::number($value));

        // Satu EVAL atomik: bucket, count, dan sum tidak pernah terbaca setengah jadi.
        self::write(static fn (PhpRedisConnection $redis): mixed => $redis->eval(self::HISTOGRAM_SCRIPT, 1, self::HISTOGRAMS_KEY, ...$arguments));
    }

    /** @return array{counters: array<string, string>, histograms: array<string, string>} */
    public static function snapshot(): array
    {
        $redis = self::redis();

        return [
            'counters' => self::hash($redis->command('hgetall', [self::COUNTERS_KEY])),
            'histograms' => self::hash($redis->command('hgetall', [self::HISTOGRAMS_KEY])),
        ];
    }

    public static function reset(): void
    {
        self::redis()->command('del', [self::COUNTERS_KEY, self::HISTOGRAMS_KEY]);
    }

    public static function number(float $value): string
    {
        return is_finite($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : '+Inf';
    }

    /** @param array<string, string> $labels */
    private static function field(string $name, string $type, array $labels): string
    {
        $definition = self::DEFINITIONS[$name] ?? null;
        if ($definition === null || $definition['type'] !== $type) {
            throw new InvalidArgumentException("Metrik {$type} '{$name}' tidak terdaftar di Metrics::DEFINITIONS.");
        }

        $keys = array_keys($labels);
        sort($keys);
        $expected = $definition['labels'];
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException("Label metrik '{$name}' harus tepat: ".implode(',', $definition['labels']).'.');
        }

        ksort($labels);

        return $name."\x1F".json_encode(array_map('strval', $labels), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param callable(PhpRedisConnection): mixed $operation */
    private static function write(callable $operation): void
    {
        try {
            $operation(self::redis());
        } catch (Throwable) {
            // Sengaja diam: kehilangan satu sampel lebih murah daripada request/job gagal.
        }
    }

    /** @return array<string, string> */
    private static function hash(mixed $raw): array
    {
        $hash = [];
        foreach (is_array($raw) ? $raw : [] as $field => $value) {
            if (is_string($field) && is_scalar($value)) {
                $hash[$field] = (string) $value;
            }
        }

        return $hash;
    }

    /** PhpRedisConnection dan PredisConnection sama-sama menerima eval($script, $numKeys, ...$args). */
    private static function redis(): PhpRedisConnection
    {
        $connection = config('observability.metrics_redis_connection', 'default');

        /** @var PhpRedisConnection */
        return Redis::connection(is_string($connection) ? $connection : 'default');
    }
}
