<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use Carbon\CarbonInterface;

interface PaymentProvider
{
    public function name(): string;

    public function createIntent(string $intentCode, int $amount, string $currency, CarbonInterface $expires): string;
}
