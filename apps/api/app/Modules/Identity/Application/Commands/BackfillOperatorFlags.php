<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BackfillOperatorFlags extends Command
{
    protected $signature = 'identity:backfill-operator-flags';

    protected $description = 'Backfill is_operator dari kind lama; is_admin=false untuk semua baris.';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'kind')) {
            $this->info('kolom kind lama sudah tidak ada - backfill no-op (idempoten).');

            return self::SUCCESS;
        }

        $total = 0;
        do {
            $affected = DB::transaction(function (): int {
                $affected = DB::update(
                    "UPDATE users SET is_operator = (kind = 'staff'), is_admin = false
                     WHERE id IN (
                         SELECT id FROM users
                         WHERE is_operator IS DISTINCT FROM (kind = 'staff') OR is_admin IS DISTINCT FROM false
                         ORDER BY id LIMIT 1000
                     )"
                );
                DB::table('audit_logs')->insert([
                    'action' => 'backfill.operator_flags',
                    'target_type' => 'users',
                    'target_id' => 0,
                    'after' => json_encode(['updated' => $affected], JSON_THROW_ON_ERROR),
                    'reason' => 'Backfill struktural kind lama; is_admin=false semua baris; bypass hanya diberikan manual dan diaudit.',
                    'request_id' => (string) Str::ulid(),
                    'occurred_at' => now(),
                ]);

                return $affected;
            });
            $total += $affected;
            if ($affected > 0) {
                usleep(200_000);
            }
        } while ($affected > 0);

        $this->info("backfill selesai: {$total} baris diperbarui; is_admin=false untuk setiap baris.");

        return self::SUCCESS;
    }
}
