<?php

declare(strict_types=1);

namespace Tests\Concurrency\Harness;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Concurrency\ConcurrencyAssertions;
use Tests\Support\Concurrency\ParallelRunner;
use Tests\Support\Concurrency\StockQuotaFixture;
use Tests\TestCase;

final class ParallelHarnessTest extends TestCase
{
    /** @return iterable<string, array{string, int, int, int}> */
    public static function workloads(): iterable
    {
        yield 'stock oversell attempt' => ['stock', 10, 2, 30];
        yield 'quota exhaustion' => ['quota', 50, 4, 30];
        yield 'empty stock' => ['stock', 0, 2, 5];
        yield 'no attempts' => ['quota', 50, 2, 0];
    }

    #[DataProvider('workloads')]
    public function test_parallel_clients_preserve_capacity(string $kind, int $capacity, int $workers, int $attempts): void
    {
        $fixture = new StockQuotaFixture(stock: $kind === 'stock' ? $capacity : 10, quota: $kind === 'quota' ? $capacity : 50);
        try {
            $results = (new ParallelRunner)->run($fixture->schema, $kind, $workers, $attempts);
            $accepted = array_sum(array_column($results, 'accepted'));
            $remaining = $fixture->remaining($kind);
            $observations = [$remaining];
            foreach ($results as $result) {
                array_push($observations, ...$result['observations']);
                $this->assertCount($attempts, $result['observations']);
            }
            $this->assertCount($workers, array_unique(array_column($results, 'pid')));
            $this->assertCount($workers, array_unique(array_column($results, 'backend')));
            $this->assertNotContains(getmypid(), array_column($results, 'pid'));
            $this->assertSame(min($capacity, $workers * $attempts), $accepted);
            ConcurrencyAssertions::neverNegative($observations);
            ConcurrencyAssertions::acceptedWithinCapacity($capacity, $accepted);
            ConcurrencyAssertions::conserved($capacity, $remaining, $accepted);
        } finally {
            $fixture->close();
        }
        $this->assertNull(DB::selectOne('SELECT schema_name FROM information_schema.schemata WHERE schema_name = ?', [$fixture->schema]));
    }

    public function test_runner_defaults_to_two_clients(): void
    {
        $fixture = new StockQuotaFixture;
        try {
            $results = (new ParallelRunner)->run($fixture->schema, 'stock');
            $this->assertCount(2, $results);
            $this->assertSame(10, array_sum(array_column($results, 'accepted')));
        } finally {
            $fixture->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        yield 'worker exception' => ['fail'];
        yield 'worker timeout' => ['timeout'];
    }

    #[DataProvider('failures')]
    public function test_worker_failures_are_not_silent(string $mode): void
    {
        $fixture = new StockQuotaFixture;
        $started = microtime(true);
        try {
            try {
                (new ParallelRunner)->run($fixture->schema, 'stock', timeout: 2, mode: $mode);
                $this->fail('A failed or timed-out worker must fail the workload.');
            } catch (RuntimeException $failure) {
                $this->assertStringContainsString('Parallel workload failed:', $failure->getMessage());
                $this->assertStringContainsString($mode === 'fail' ? 'Deliberate worker failure.' : 'exceeded the timeout', $failure->getMessage());
            }
            $this->assertLessThan(5, microtime(true) - $started);
            $this->assertSame(10, $fixture->remaining('stock'));
        } finally {
            $fixture->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function violations(): iterable
    {
        yield 'negative sample despite valid final value' => ['negative'];
        yield 'missing observations' => ['empty'];
        yield 'oversold' => ['capacity'];
        yield 'negative accepted' => ['accepted'];
        yield 'lost update' => ['conservation'];
    }

    #[DataProvider('violations')]
    public function test_assertions_reject_bad_data(string $violation): void
    {
        $this->expectException(AssertionFailedError::class);
        match ($violation) {
            'negative' => ConcurrencyAssertions::neverNegative([10, -1, 0]),
            'empty' => ConcurrencyAssertions::neverNegative([]),
            'capacity' => ConcurrencyAssertions::acceptedWithinCapacity(10, 11),
            'accepted' => ConcurrencyAssertions::acceptedWithinCapacity(10, -1),
            'conservation' => ConcurrencyAssertions::conserved(10, 1, 10),
        };
    }

    public function test_invalid_workload_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ParallelRunner)->run('public', 'stock', workers: 0);
    }
}
