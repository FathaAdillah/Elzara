<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use Carbon\CarbonImmutable;

final readonly class CheckoutPaymentIntent
{
    public function __construct(
        public int $id,
        public string $code,
        public int $invoiceId,
        public string $provider,
        public string $status,
        public CarbonImmutable $expiresAt,
    ) {}
}
