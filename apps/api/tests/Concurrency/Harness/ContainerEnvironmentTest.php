<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\Support\Concurrency\ConcurrencyTestCase;
use Tests\Support\Concurrency\ContainerEnvironment;

final class ContainerEnvironmentTest extends ConcurrencyTestCase
{
    public function test_parent_and_fresh_workers_use_migrated_container_services(): void
    {
        $environment = ContainerEnvironment::start();
        $this->assertSame($environment['DB_PORT'], config('database.connections.pgsql.port'));
        $this->assertSame($environment['REDIS_PORT'], config('database.redis.default.port'));
        $this->assertGreaterThan(0, DB::table('migrations')->count());
        Redis::set('concurrency-probe', 'ready');

        $process = new Process([PHP_BINARY, '-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo Illuminate\Support\Facades\DB::table('migrations')->count() > 0
    ? Illuminate\Support\Facades\Redis::get('concurrency-probe') : 'unmigrated';
PHP], base_path(), $environment, timeout: 10);
        try {
            $process->mustRun();
            $this->assertSame('ready', $process->getOutput());
        } finally {
            Redis::del('concurrency-probe');
        }
    }
}
