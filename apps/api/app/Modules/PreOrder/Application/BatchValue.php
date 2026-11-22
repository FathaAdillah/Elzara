<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Application;

use Carbon\CarbonImmutable;
use UnexpectedValueException;

final class BatchValue
{
    public static function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new UnexpectedValueException('Invalid preorder integer.');
        }

        return $value;
    }

    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Invalid preorder text.');
        }

        return $value;
    }

    public static function time(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse(self::text($value));
    }
}
