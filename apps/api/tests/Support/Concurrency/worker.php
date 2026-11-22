<?php

declare(strict_types=1);

use App\Modules\PreOrder\Contracts\BatchCheckout;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

try {
    $settings = json_decode(trim((string) fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.harness' => $settings['database']]);
    $db = DB::connection('harness');
    if ($settings['kind'] === 'preorder') {
        DB::setDefaultConnection('harness');
        $db->statement("SET search_path TO {$settings['schema']}, public");
    }
    $backend = (int) $db->selectOne('SELECT pg_backend_pid() AS pid')->pid;
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Start barrier was closed.');
    }
    if ($settings['mode'] === 'fail') {
        throw new RuntimeException('Deliberate worker failure.');
    }
    if ($settings['mode'] === 'timeout') {
        sleep(60);
    }
    $accepted = 0;
    $observations = [];
    for ($i = 0; $i < $settings['attempts']; $i++) {
        if ($settings['kind'] === 'preorder') {
            try {
                $checkout = $app->make(BatchCheckout::class);
                $checkout->enroll($checkout->offer(1), getmypid(), 1, getmypid() * 1000 + $i, 1, now()->addHour());
                $accepted++;
            } catch (HttpResponseException $failure) {
                $body = json_decode($failure->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
                if (($body['code'] ?? null) !== 'BATCH_QUOTA_EXHAUSTED') {
                    throw $failure;
                }
            }
            $observations[] = (int) $db->table('preorder_batch_items')->value('reserved_qty');

            continue;
        }
        $row = $db->selectOne("UPDATE {$settings['schema']}.capacities SET remaining = remaining - 1 WHERE kind = ? AND remaining >= 1 RETURNING remaining", [$settings['kind']]);
        if ($row !== null) {
            $accepted++;
            $observations[] = (int) $row->remaining;
        } else {
            $observations[] = (int) $db->table($settings['schema'].'.capacities')->where('kind', $settings['kind'])->value('remaining');
        }
    }
    fwrite(STDOUT, json_encode(['pid' => getmypid(), 'backend' => $backend, 'accepted' => $accepted, 'observations' => $observations], JSON_THROW_ON_ERROR));
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage()."\n");
    exit(1);
}
