<?php

declare(strict_types=1);

namespace App\Platform\Domain\Statuses;

/** Sumbu 2 dari empat, pemilik Payments + Billing (Doc 01 §5); rename dari money_status (B-3). */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Disputed = 'disputed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::Unpaid;
    }
}
