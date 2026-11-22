<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Providers;

use App\Modules\Payments\Contracts\PaymentProvider;
use Carbon\CarbonInterface;

final class FakePaymentProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'fake';
    }

    public function createIntent(string $intentCode, int $amount, string $currency, CarbonInterface $expires): string
    {
        return 'fake_'.$intentCode;
    }
}
