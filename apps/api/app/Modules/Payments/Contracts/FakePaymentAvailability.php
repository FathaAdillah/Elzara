<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

final class FakePaymentAvailability
{
    public static function enabled(): bool
    {
        return (App::environment('local') || App::environment('testing')) && Config::boolean('checkout.fake_payment_enabled');
    }
}
