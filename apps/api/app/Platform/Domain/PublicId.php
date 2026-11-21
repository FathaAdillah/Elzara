<?php

declare(strict_types=1);

namespace App\Platform\Domain;

use InvalidArgumentException;

final class PublicId
{
    private const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function encode(string $prefix, int $id): string
    {
        if (! in_array($prefix, ['prd', 'var', 'media'], true) || $id < 1) {
            throw new InvalidArgumentException('Invalid public id.');
        }
        $value = '';
        while ($id > 0) {
            $value = self::DIGITS[$id % 62].$value;
            $id = intdiv($id, 62);
        }

        return $prefix.'_'.str_pad($value, 20, '0', STR_PAD_LEFT);
    }

    public static function decode(string $value, string $prefix): int
    {
        if (! in_array($prefix, ['prd', 'var', 'media'], true) || preg_match('/\A'.preg_quote($prefix, '/').'_[0-9A-Za-z]{20,32}\z/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid public id.');
        }
        $id = 0;
        foreach (str_split(substr($value, strlen($prefix) + 1)) as $digit) {
            $number = strpos(self::DIGITS, $digit);
            if ($number === false || $id > intdiv(PHP_INT_MAX - $number, 62)) {
                throw new InvalidArgumentException('Public id overflow.');
            }
            $id = $id * 62 + $number;
        }
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid public id.');
        }

        return $id;
    }
}
