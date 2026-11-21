<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Platform\Domain\PublicId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PublicIdTest extends TestCase
{
    public function test_round_trips_without_losing_integer_precision(): void
    {
        foreach (['prd', 'var', 'media'] as $prefix) {
            foreach ([1, 62, 123, PHP_INT_MAX] as $id) {
                $encoded = PublicId::encode($prefix, $id);
                $this->assertMatchesRegularExpression('/^'.$prefix.'_[0-9A-Za-z]{20}$/', $encoded);
                $this->assertSame($id, PublicId::decode($encoded, $prefix));
            }
        }
    }

    public function test_rejects_invalid_empty_zero_wrong_prefix_and_overflow_ids(): void
    {
        foreach (['', 'prd_00000000000000000000', 'var_00000000000000000001', 'prd_zzzzzzzzzzzzzzzzzzzz'] as $id) {
            try {
                PublicId::decode($id, 'prd');
                $this->fail('Expected invalid id.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        PublicId::encode('prd', 0);
    }
}
