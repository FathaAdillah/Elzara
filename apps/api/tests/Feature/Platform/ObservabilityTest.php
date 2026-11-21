<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Platform\Observability\Metrics;
use App\Platform\Observability\ObservabilityServiceProvider;
use App\Platform\Observability\StructuredLogProcessor;
use DateTimeImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use League\Flysystem\UnableToReadFile;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\TestCase;

final class ObservabilityTest extends TestCase
{
    private const PARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    protected function setUp(): void
    {
        parent::setUp();
        Metrics::reset();
        Route::get('api/v1/_observability-probe', static fn () => response()->json([
            'request_id' => Context::get('request_id'),
            'trace_id' => Context::get('trace_id'),
            'parent_span_id' => Context::get('parent_span_id'),
        ]));
    }

    protected function tearDown(): void
    {
        Metrics::reset();
        parent::tearDown();
    }

    public function test_incoming_traceparent_is_continued_with_a_new_server_span(): void
    {
        $response = $this->getJson('/api/v1/_observability-probe', ['traceparent' => self::PARENT, 'X-Request-Id' => 'req-trace-1']);

        $response->assertOk()
            ->assertJson(['request_id' => 'req-trace-1', 'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'parent_span_id' => '00f067aa0ba902b7']);
        $this->assertMatchesRegularExpression('/\A00-4bf92f3577b34da6a3ce929d0e0e4736-(?!00f067aa0ba902b7)[0-9a-f]{16}-01\z/', (string) $response->headers->get('traceparent'));
    }

    public function test_invalid_or_missing_traceparent_starts_a_new_trace(): void
    {
        foreach (['00-00000000000000000000000000000000-00f067aa0ba902b7-01', 'garbage', null] as $header) {
            $response = $this->getJson('/api/v1/_observability-probe', $header === null ? [] : ['traceparent' => $header]);

            $response->assertOk()->assertJson(['parent_span_id' => null]);
            $traceId = $response->json('trace_id');
            $this->assertIsString($traceId);
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $traceId);
            $this->assertNotSame(str_repeat('0', 32), $traceId);
            $this->assertStringStartsWith("00-{$traceId}-", (string) $response->headers->get('traceparent'));
        }
    }

    public function test_queued_job_inherits_the_trace_and_opens_a_child_span(): void
    {
        Context::add('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');
        Context::add('span_id', '00f067aa0ba902b7');
        $seen = [];
        Queue::after(static function () use (&$seen): void {
            $seen = Context::only(['trace_id', 'span_id', 'parent_span_id']);
        });

        dispatch(static function (): void {})->onConnection('sync');

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $seen['trace_id'] ?? null);
        $this->assertSame('00f067aa0ba902b7', $seen['parent_span_id'] ?? null);
        $this->assertNotSame('00f067aa0ba902b7', $seen['span_id'] ?? null);
        $this->assertStringContainsString(
            'elzara_queue_jobs_total{outcome="processed",queue="sync"} 1',
            $this->get('/metrics')->assertOk()->getContent() ?: '',
        );
    }

    public function test_metrics_endpoint_exposes_request_queue_and_database_series(): void
    {
        $this->getJson('/api/v1/_observability-probe')->assertOk();
        $this->getJson('/api/v1/_observability-probe')->assertOk();

        $response = $this->get('/metrics');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain; version=0.0.4', (string) $response->headers->get('Content-Type'));
        $body = $response->getContent() ?: '';
        $this->assertStringContainsString('elzara_http_requests_total{method="GET",route="/api/v1/_observability-probe",status="200"} 2', $body);
        $this->assertStringContainsString('elzara_http_request_duration_seconds_count{method="GET",route="/api/v1/_observability-probe"} 2', $body);
        $this->assertStringContainsString('elzara_http_request_duration_seconds_bucket{method="GET",route="/api/v1/_observability-probe",le="+Inf"} 2', $body);
        $this->assertStringContainsString('elzara_oversell_anomalies_total 0', $body);
        $this->assertStringContainsString('# TYPE elzara_webhook_delay_seconds histogram', $body);
        $this->assertStringContainsString('elzara_metrics_collector_up{collector="registry"} 1', $body);
        $this->assertStringContainsString('elzara_metrics_collector_up{collector="postgres"} 1', $body);
        $this->assertStringContainsString('elzara_queue_lag_seconds{queue="payments"}', $body);
        $this->assertStringContainsString('elzara_queue_dlq_jobs{queue="payments"} 0', $body);
        $this->assertMatchesRegularExpression('/^elzara_pg_lock_waits \d+$/m', $body);
        $this->assertMatchesRegularExpression('/^elzara_pg_wal_archive_failures_total \d+$/m', $body);
        $this->assertStringNotContainsString('route="/metrics"', $body);
    }

    public function test_metrics_endpoint_is_hidden_from_public_clients(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/metrics')->assertNotFound();
    }

    public function test_unknown_metric_names_and_labels_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Metrics::increment('elzara_http_requests_total', ['method' => 'GET']);
    }

    public function test_log_processor_redacts_pii_and_hashes_user_ids(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'stderr',
            level: Level::Warning,
            message: 'OTP untuk budi@example.com ke 081234567890 dengan Bearer abc.def',
            context: ['user_id' => 42, 'order_id' => 7, 'password' => 'hunter2', 'nested' => ['email' => 'x@y.id', 'note' => 'call +6281234567890']],
            extra: ['request_id' => 'req-1', 'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736'],
        );

        $clean = (new StructuredLogProcessor('elzara-api', 'testing'))($record);

        $this->assertSame('OTP untuk [email] ke [phone] dengan Bearer [redacted]', $clean->message);
        $this->assertArrayNotHasKey('user_id', $clean->context);
        $this->assertSame(StructuredLogProcessor::hashUser(42), $clean->context['user_hash']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $clean->context['user_hash']);
        $this->assertSame(7, $clean->context['order_id']);
        $this->assertSame('[redacted]', $clean->context['password']);
        $this->assertSame(['email' => '[redacted]', 'note' => 'call [phone]'], $clean->context['nested']);
        $this->assertSame(['request_id' => 'req-1', 'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'service' => 'elzara-api', 'environment' => 'testing'], $clean->extra);
    }

    public function test_stderr_channel_emits_one_json_object_per_line_with_correlation(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        config(['logging.channels.json_probe' => array_replace(config('logging.channels.stderr'), ['handler_with' => ['stream' => $stream]])]);
        Context::add('request_id', 'req-json-1');
        Context::add('trace_id', '4bf92f3577b34da6a3ce929d0e0e4736');

        logger()->channel('json_probe')->warning('Pembayaran {provider} gagal untuk {email}', ['provider' => 'midtrans', 'email' => 'a@b.co', 'user_id' => 9]);

        rewind($stream);
        $line = json_decode((string) stream_get_contents($stream), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($line);
        $this->assertSame('Pembayaran midtrans gagal untuk [redacted]', $line['message']);
        $this->assertSame('req-json-1', $line['extra']['request_id']);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $line['extra']['trace_id']);
        $this->assertSame('[redacted]', $line['context']['email']);
        $this->assertSame(StructuredLogProcessor::hashUser(9), $line['context']['user_hash']);
    }

    public function test_exception_in_log_context_is_normalised_and_redacted(): void
    {
        $previous = new RuntimeException('token for budi@example.com');
        $record = new LogRecord(new DateTimeImmutable, 'stderr', Level::Error, 'gagal', [
            'exception' => new RuntimeException('Bearer abc.def gagal untuk 081234567890', 3, $previous),
        ]);

        $exception = (new StructuredLogProcessor('elzara-api', 'testing'))($record)->context['exception'];

        $this->assertIsArray($exception);
        $this->assertSame(RuntimeException::class, $exception['class']);
        $this->assertSame('Bearer [redacted] gagal untuk [phone]', $exception['message']);
        $this->assertSame(3, $exception['code']);
        $this->assertSame('token for [email]', $exception['previous']['message']);
        $this->assertStringNotContainsString('budi@example.com', json_encode($exception, JSON_THROW_ON_ERROR));
    }

    public function test_storage_errors_are_counted_even_when_wrapped(): void
    {
        ObservabilityServiceProvider::reportStorageError(new RuntimeException('Object storage unavailable.', 0, UnableToReadFile::fromLocation('a.jpg')));
        ObservabilityServiceProvider::reportStorageError(new RuntimeException('bukan storage'));

        $counters = array_filter(Metrics::snapshot()['counters'], static fn (string $field): bool => str_starts_with($field, 'elzara_storage_errors_total'), ARRAY_FILTER_USE_KEY);
        $this->assertSame(1.0, (float) array_sum($counters));
        $this->assertStringContainsString('UnableToReadFile', (string) array_key_first($counters));
    }
}
