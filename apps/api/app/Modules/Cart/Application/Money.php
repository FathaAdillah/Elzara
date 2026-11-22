<?php

declare(strict_types=1);

namespace App\Modules\Cart\Application;

final readonly class Money
{
    public function __construct(public int $amount_minor, public string $currency = 'IDR') {}
}
