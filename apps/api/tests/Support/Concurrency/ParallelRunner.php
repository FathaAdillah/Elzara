<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Throwable;

final class ParallelRunner
{
    /** @return list<array{pid: int, backend: int, accepted: int, observations: list<int>}> */
    public function run(string $schema, string $kind, int $workers = 2, int $attempts = 20, float $timeout = 10, string $mode = 'reserve'): array
    {
        if (! preg_match('/^harness_[a-f0-9]{16}$/D', $schema) || ! in_array($kind, ['stock', 'quota', 'preorder'], true)
            || $workers < 1 || $attempts < 0 || $timeout <= 0 || ! in_array($mode, ['reserve', 'fail', 'timeout'], true)) {
            throw new InvalidArgumentException('Invalid parallel workload.');
        }
        $processes = [];
        $inputs = [];
        $deadline = microtime(true) + $timeout;
        try {
            for ($i = 0; $i < $workers; $i++) {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, __DIR__.'/worker.php'], base_path(), ContainerEnvironment::start(), timeout: $timeout);
                $process->setInput($input);
                $processes[] = $process;
                $inputs[] = $input;
                $process->start();
                $input->write(json_encode([
                    'database' => DB::connection()->getConfig(),
                    'schema' => $schema, 'kind' => $kind, 'attempts' => $attempts, 'mode' => $mode,
                ], JSON_THROW_ON_ERROR)."\n");
            }
            do {
                $ready = true;
                foreach ($processes as $index => $process) {
                    $process->checkTimeout();
                    if (! $process->isRunning()) {
                        throw new RuntimeException("Worker {$index} exited before barrier: ".$process->getErrorOutput());
                    }
                    $ready = $ready && str_starts_with($process->getOutput(), "READY\n");
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Workers timed out at start barrier.');
                }
                if (! $ready) {
                    usleep(1000);
                }
            } while (! $ready);
            foreach ($inputs as $input) {
                $input->write("GO\n");
                $input->close();
            }
            $results = [];
            foreach ($processes as $index => $process) {
                if ($process->wait() !== 0) {
                    throw new RuntimeException("Worker {$index} failed: ".$process->getErrorOutput());
                }
                $results[] = json_decode(substr($process->getOutput(), strlen("READY\n")), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } catch (Throwable $failure) {
            throw new RuntimeException('Parallel workload failed: '.$failure->getMessage(), previous: $failure);
        } finally {
            foreach ($processes as $process) {
                $process->stop(0);
            }
            foreach ($inputs as $input) {
                $input->close();
            }
        }
    }
}
