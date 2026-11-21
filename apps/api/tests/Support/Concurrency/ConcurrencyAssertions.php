<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use PHPUnit\Framework\Assert;

final class ConcurrencyAssertions
{
    /** @param list<int> $observations */
    public static function neverNegative(array $observations): void
    {
        Assert::assertNotEmpty($observations, 'Availability observations are required.');
        foreach ($observations as $remaining) {
            Assert::assertGreaterThanOrEqual(0, $remaining, 'Availability became negative.');
        }
    }

    public static function acceptedWithinCapacity(int $initial, int $accepted): void
    {
        Assert::assertGreaterThanOrEqual(0, $accepted);
        Assert::assertLessThanOrEqual($initial, $accepted, 'Accepted quantity exceeded capacity.');
    }

    public static function conserved(int $initial, int $remaining, int $accepted): void
    {
        Assert::assertSame($initial, $remaining + $accepted, 'Capacity was not conserved.');
    }
}
