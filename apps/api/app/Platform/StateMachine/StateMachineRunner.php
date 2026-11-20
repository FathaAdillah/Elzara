<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Satu-satunya jalur perubahan status (B5); korektnya dijaga conditional UPDATE, bukan lock Redis. */
final class StateMachineRunner
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly ?TransitionSink $sink = null,
    ) {}

    /** @throws InvalidTransition */
    public function transition(
        string $table,
        int $aggregateId,
        Axis $axis,
        string $to,
        ?string $ifFrom = null,
        ?string $reason = null,
    ): string {
        if (! $axis->isValue($to)) {
            throw new InvalidTransition($axis, $ifFrom ?? '', $to);
        }

        $reason = trim($reason ?? '');
        if ($this->sink !== null && $reason === '') {
            throw new InvalidArgumentException('Alasan transisi wajib diisi untuk pencatatan riwayat.');
        }

        $connection = $this->db();

        return $connection->transaction(function () use ($connection, $table, $aggregateId, $axis, $to, $ifFrom, $reason): string {
            $column = $axis->value;
            $columns = $axis === Axis::Fulfillment ? [$column, 'fulfillment_lane'] : [$column];
            $row = $connection->table($table)->where('id', $aggregateId)->lockForUpdate()->first($columns);
            if ($row === null) {
                throw new InvalidTransition($axis, '', $to);
            }

            $fromValue = $row->{$column} ?? null;
            $from = is_string($fromValue) ? $fromValue : '';

            if ($ifFrom !== null && $from !== $ifFrom) {
                throw new InvalidTransition($axis, $from, $to);
            }

            $lane = null;
            if ($axis === Axis::Fulfillment) {
                $laneValue = $row->fulfillment_lane ?? null;
                $lane = is_string($laneValue) ? FulfillmentLane::tryFrom($laneValue) : null;
                if ($lane === null) {
                    throw new InvalidTransition($axis, $from, $to);
                }
            }

            if ($from === $to || ! TransitionGraph::allows($axis, $from, $to, $lane)) {
                throw new InvalidTransition($axis, $from, $to);
            }

            // UPDATE ... WHERE <sumbu> = :from (Doc 01 §6.3 K5): nol baris = state berubah paralel, bukan keberhasilan hening.
            $updated = $connection->table($table)
                ->where('id', $aggregateId)
                ->where($column, $from)
                ->update([$column => $to, 'updated_at' => now()]);

            if ($updated !== 1) {
                throw new InvalidTransition($axis, $from, $to);
            }

            $this->sink?->record($connection, $table, $aggregateId, $axis, $from, $to, $reason);

            return $to;
        });
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
