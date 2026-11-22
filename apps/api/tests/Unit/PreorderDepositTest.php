<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\PreOrder\Contracts\BatchOffer;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PreorderDepositTest extends TestCase
{
    #[TestWith([90001, 'percent', 5000, 1, 45001])]
    #[TestWith([90001, 'percent', 5000, 2, 90001])]
    #[TestWith([100, 'flat', 500, 1, 100])]
    #[TestWith([100, 'flat', 50, 2, 50])]
    #[TestWith([100, 'percent', 0, 1, 0])]
    #[TestWith([0, 'flat', 500, 1, 0])]
    public function test_minor_unit_deposit(int $price, string $kind, int $value, int $quantity, int $expected): void
    {
        $this->assertSame($expected, (new BatchOffer(1, 1, $price, $kind, $value))->deposit($quantity));
    }
}
