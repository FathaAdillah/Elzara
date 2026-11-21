<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

try {
    $settings = json_decode(trim((string) fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.connections.harness' => $settings['database']]);
    $db = DB::connection('harness');
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
