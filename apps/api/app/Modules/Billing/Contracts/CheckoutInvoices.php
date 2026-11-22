<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use Carbon\CarbonInterface;

interface CheckoutInvoices
{
    /** @return array{id:int, code:string} */
    public function issue(int $order, int $amount, CarbonInterface $expires): array;

    public function pay(int $invoice, CarbonInterface $now): void;

    public function void(int $invoice, CarbonInterface $now): void;
}
