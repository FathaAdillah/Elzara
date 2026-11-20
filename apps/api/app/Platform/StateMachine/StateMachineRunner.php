<?php

declare(strict_types=1);

namespace App\Platform\StateMachine;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/** Satu-satunya jalur perubahan status (B5); korektnya dijaga conditional UPDATE, bukan lock Redis. */
final class StateMachineRunner
{
    public function __construct(private readonly ?string $connection = null)
    {
    }

    /** @throws InvalidTransition */
    public function transition(string $table, int $aggregateId, Axis $axis, string $to, ?string $ifFrom = null): string
    {
        if (!$axis->isValue($to)) {
            throw new InvalidTransition($axis, $ifFrom ?? '', $to);
        }

        return $this->db()->transaction(function () use ($table, $aggregateId, $axis, $to, $ifFrom): string {
            $column = $axis->value;
            $columns = $axis === Axis::Fulfillment ? [$column, 'fulfillment_lane'] : [$column];
            $row = $this->db()->table($table)->where('id', $aggregateId)->lockForUpdate()->first($columns);
            $from = (string) ($row?->{$column} ?? '');

            if ($ifFrom !== null && $from !== $ifFrom) {
                throw new InvalidTransition($axis, $from, $to);
            }

            $lane = null;
            if ($axis === Axis::Fulfillment) {
                $lane = FulfillmentLane::tryFrom((string) ($row?->fulfillment_lane ?? ''));
                if ($lane === null) {
                    throw new InvalidTransition($axis, $from, $to);
                }
            }

            if (!TransitionGraph::allows($axis, $from, $to, $lane)) {
                throw new InvalidTransition($axis, $from, $to);
            }

            // UPDATE ... WHERE <sumbu> = :from (Doc 01 §6.3 K5): nol baris = state berubah paralel, bukan keberhasilan hening.
            $updated = $this->db()->table($table)
                ->where('id', $aggregateId)
                ->where($column, $from)
                ->update([$column => $to, 'updated_at' => now()]);

            if ($updated !== 1) {
                throw new InvalidTransition($axis, $from, $to);
            }

            return $to;
        });
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
