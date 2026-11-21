<?php

declare(strict_types=1);

namespace App\Platform\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class WireTime
{
    public static function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
