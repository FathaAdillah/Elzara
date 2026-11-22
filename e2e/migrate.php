<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/../apps/api/vendor/autoload.php';
$app = require __DIR__.'/../apps/api/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$connection = $app->make('db')->connection('pgsql')->getConfig();
$expected = [
    'driver' => 'pgsql',
    'host' => 'postgres',
    'port' => '5432',
    'database' => 'elzara_e2e',
    'username' => 'elzara',
    'password' => 'elzara',
    'schema' => 'public',
    'search_path' => 'public',
];

foreach ($expected as $key => $value) {
    if (($connection[$key] ?? null) !== $value) {
        fwrite(STDERR, "Refusing migrate:fresh: unexpected E2E database {$key}.\n");
        exit(1);
    }
}
if (! empty($connection['url']) || ! empty($connection['read']) || ! empty($connection['write'])) {
    fwrite(STDERR, "Refusing migrate:fresh: E2E database overrides are not allowed.\n");
    exit(1);
}

exit($kernel->call('migrate:fresh', ['--database' => 'pgsql', '--force' => true], new ConsoleOutput));
