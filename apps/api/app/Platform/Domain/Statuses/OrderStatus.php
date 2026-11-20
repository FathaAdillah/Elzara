<?php

declare(strict_types=1);

namespace App\Platform\Domain\Statuses;

/** Sumbu 1 dari empat (Doc 01 §5); pemilik nilai final = CHECK Doc 09 §6, tidak ada daftar kedua. */
enum OrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case RefundRequested = 'refund_requested';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::AwaitingPayment;
    }
}
