<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StockQuotaFixture
{
    public readonly string $schema;

    public function __construct(int $stock = 10, int $quota = 50)
    {
        if ($stock < 0 || $quota < 0) {
            throw new InvalidArgumentException('Capacities must be nonnegative.');
        }
        if (DB::transactionLevel() !== 0 || DB::getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('Fixtures require committed PostgreSQL connections.');
        }
        $this->schema = 'harness_'.bin2hex(random_bytes(8));
        DB::statement("CREATE SCHEMA {$this->schema}");
        try {
            DB::statement("CREATE TABLE {$this->schema}.capacities (kind text PRIMARY KEY, remaining integer NOT NULL CHECK (remaining >= 0))");
            DB::table($this->schema.'.capacities')->insert([
                ['kind' => 'stock', 'remaining' => $stock],
                ['kind' => 'quota', 'remaining' => $quota],
            ]);
        } catch (\Throwable $failure) {
            $this->close();
            throw $failure;
        }
    }

    public function remaining(string $kind): int
    {
        return (int) DB::table($this->schema.'.capacities')->where('kind', $kind)->value('remaining');
    }

    public function close(): void
    {
        DB::statement("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
    }
}
