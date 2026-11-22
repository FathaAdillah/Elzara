<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use Illuminate\Database\ConnectionInterface;

interface TransitionSink
{
    /** Tulis riwayat melalui koneksi yang diberikan agar ikut dalam transaksi transisi. */
    public function record(
        ConnectionInterface $connection,
        string $table,
        int $aggregateId,
        Axis $axis,
        string $from,
        string $to,
        string $reason,
        ?int $actorId = null,
    ): void;
}
