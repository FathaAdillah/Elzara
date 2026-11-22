<?php

declare(strict_types=1);

namespace App\Platform\Domain;

use UnexpectedValueException;

/** Penyempitan nilai `mixed` dari query builder / JSON eksternal ke skalar, gagal keras alih-alih cast diam-diam. */
final class Scalar
{
    public static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Nilai bukan bilangan bulat.');
    }

    /** @return array<string, mixed> */
    public static function fields(object $row): array
    {
        $fields = [];
        foreach (get_object_vars($row) as $key => $value) {
            $fields[(string) $key] = $value;
        }

        return $fields;
    }

    public static function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => throw new UnexpectedValueException('Nilai bukan skalar.'),
        };
    }
}
