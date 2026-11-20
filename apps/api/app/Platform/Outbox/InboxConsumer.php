<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final class InboxConsumer
{
    public function __construct(private readonly ?string $connection = null) {}

    /**
     * Efek hanya menulis lewat koneksi yang diberikan agar ikut dalam transaksi inbox.
     *
     * @param array<string, mixed> $payload
     * @param callable(ConnectionInterface): void $effect
     */
    public function run(string $consumer, string $eventId, array $payload, callable $effect): bool
    {
        $database = DB::connection($this->connection);
        if ($database->transactionLevel() !== 0) {
            throw new LogicException('Konsumen inbox harus menjalankan transaksi sendiri.');
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $result = $database->transaction(function () use ($database, $consumer, $eventId, $encoded, $effect): bool|Throwable {
            $database->table('inbox_events')->insertOrIgnore([
                'consumer' => $consumer,
                'event_id' => $eventId,
                'payload' => $encoded,
                'status' => 'pending',
            ]);

            $record = $database->table('inbox_events')
                ->where('consumer', $consumer)
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->firstOrFail(['id', 'status']);
            if (!in_array($record->status, ['pending', 'failed'], true)) {
                return false;
            }

            $row = $database->table('inbox_events')->where('id', $record->id);
            // Savepoint membatalkan efek yang gagal sambil mempertahankan klaim baris inbox.
            $database->beginTransaction();
            try {
                $effect($database);
                $row->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'error' => null,
                ]);
                $database->commit();
            } catch (Throwable $exception) {
                $database->rollBack(1);
                $row->update([
                    'status' => 'failed',
                    'processed_at' => null,
                    'error' => 'Efek konsumen gagal.',
                ]);

                return $exception;
            }

            return true;
        });

        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!$result) {
            Log::info('inbox.dedup_ignored', ['consumer' => $consumer, 'event_id' => $eventId]);
        }

        return $result;
    }
}
