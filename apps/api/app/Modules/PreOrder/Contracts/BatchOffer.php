<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Contracts;

final readonly class BatchOffer
{
    public function __construct(public int $id, public int $batchId, public int $price, public string $depositKind, public int $depositValue) {}

    public function deposit(int $quantity): int
    {
        $total = $this->price * $quantity;

        return min($total, $this->depositKind === 'flat' ? $this->depositValue : intdiv($total * $this->depositValue + 9999, 10000));
    }
}
