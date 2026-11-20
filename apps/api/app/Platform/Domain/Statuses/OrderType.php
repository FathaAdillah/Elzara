<?php

declare(strict_types=1);

namespace App\Platform\Domain\Statuses;

/** Pembeda jalur di satu order engine (Doc 01 §5); lane pemenuhan bukan order_type (B-3). */
enum OrderType: string
{
    case ReadyStock = 'ready_stock';
    case Preorder = 'preorder';
    case RequestOrder = 'request_order';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function default(): self
    {
        return self::ReadyStock;
    }
}
