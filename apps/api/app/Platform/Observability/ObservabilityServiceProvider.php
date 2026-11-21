<?php

declare(strict_types=1);

namespace App\Platform\Observability;

use App\Platform\Http\InternalAccess;
use App\Platform\Http\TraceContext;
use Aws\Exception\AwsException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\FilesystemException;

final class ObservabilityServiceProvider extends ServiceProvider
{
    private const JOB_STARTED = 'observability_job_started';

    public function boot(): void
    {
        // Di luar grup `web`/`api`: scrape Prometheus tidak butuh sesi, CSRF, atau rate limit publik.
        Route::get('/metrics', MetricsController::class)->middleware(InternalAccess::class)->name('platform.metrics');

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $traceId = Context::get('trace_id');
            TraceContext::startSpan(is_string($traceId) ? $traceId : null, $this->stringContext('span_id'));
            Context::addHidden(self::JOB_STARTED, hrtime(true));
            Context::add('job', $event->job->resolveName());

            $queue = $event->job->getQueue();
            if ($event->job->attempts() > 1) {
                Metrics::increment('elzara_queue_job_retries_total', ['queue' => $queue]);
            }

            $createdAt = $event->job->payload()['createdAt'] ?? null;
            if (is_int($createdAt) && $event->job->attempts() === 1) {
                Metrics::observe('elzara_queue_job_wait_seconds', max(0, time() - $createdAt), ['queue' => $queue]);
            }
        });

        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $this->finished($event->job->getQueue(), 'processed'));
        Event::listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $event) => $this->finished($event->job->getQueue(), 'released'));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $this->finished($event->job->getQueue(), 'failed'));
    }

    /** Dipanggil dari bootstrap/app.php withExceptions: hitung error MinIO/S3 tanpa menelan laporannya. */
    public static function reportStorageError(FilesystemException|AwsException $e): void
    {
        Metrics::increment('elzara_storage_errors_total', ['exception' => class_basename($e)]);
    }

    private function finished(string $queue, string $outcome): void
    {
        Metrics::increment('elzara_queue_jobs_total', ['queue' => $queue, 'outcome' => $outcome]);

        $started = Context::getHidden(self::JOB_STARTED);
        if (is_int($started)) {
            Metrics::observe('elzara_queue_job_duration_seconds', (hrtime(true) - $started) / 1e9, ['queue' => $queue]);
            Context::forgetHidden(self::JOB_STARTED);
        }
    }

    private function stringContext(string $key): ?string
    {
        $value = Context::get($key);

        return is_string($value) ? $value : null;
    }
}
