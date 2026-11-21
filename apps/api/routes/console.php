<?php

declare(strict_types=1);

use App\Modules\Content\Application\ExpiredUploadCleaner;
use App\Platform\Outbox\OutboxDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('platform:outbox:dispatch {--queue=} {--limit=50}', function (OutboxDispatcher $dispatcher): int {
    $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($limit === false) {
        $this->error('Batas batch outbox harus bilangan positif.');

        return 1;
    }

    $queue = $this->option('queue');
    if ($queue !== null && ! is_string($queue)) {
        $this->error('Antrian harus berupa satu nama.');

        return 1;
    }
    try {
        $result = $dispatcher->dispatchBatch($limit, $queue);
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->line(sprintf(
        'claimed=%d dispatched=%d failed=%d dead=%d queue=%s',
        $result['claimed'], $result['dispatched'], $result['failed'], $result['dead'], $queue ?? 'all',
    ));

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Kirim event yang menunggu di transactional outbox');

Schedule::command('platform:outbox:dispatch')->everyMinute();

Artisan::command('content:media:cleanup', function (ExpiredUploadCleaner $cleaner): int {
    $result = $cleaner->run();
    $this->line(sprintf('deleted=%d failed=%d', $result['deleted'], $result['failed']));

    return $result['failed'] === 0 ? 0 : 1;
})->purpose('Hapus reservasi upload kedaluwarsa beserta seluruh versi staging');

Schedule::command('content:media:cleanup')->hourly()->withoutOverlapping();
