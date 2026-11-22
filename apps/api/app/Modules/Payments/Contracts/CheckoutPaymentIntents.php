<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use Carbon\CarbonInterface;

interface CheckoutPaymentIntents
{
    public function issue(int $order, int $invoice, int $amount, CarbonInterface $expires): CheckoutPaymentIntent;

    /** @return list<CheckoutPaymentIntent> */
    public function lockForOrder(int $order): array;

    public function capture(CheckoutPaymentIntent $intent, CarbonInterface $now): void;

    public function expire(CheckoutPaymentIntent $intent, CarbonInterface $now): void;
}
