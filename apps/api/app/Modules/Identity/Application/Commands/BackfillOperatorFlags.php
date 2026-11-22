<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Audit\Contracts\AuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BackfillOperatorFlags extends Command
{
    protected $signature = 'identity:backfill-operator-flags';

    protected $description = 'Backfill is_operator dari kind lama; is_admin=false untuk semua baris.';

    public function handle(AuditRecorder $audit): int
    {
        if (! Schema::hasColumn('users', 'kind')) {
            $this->info('kolom kind lama sudah tidak ada - backfill no-op (idempoten).');

            return self::SUCCESS;
        }

        $total = 0;
        do {
            $affected = DB::transaction(function () use ($audit): int {
                $affected = DB::update(
                    "UPDATE users SET is_operator = (kind = 'staff'), is_admin = false
                     WHERE id IN (
                         SELECT id FROM users
                         WHERE is_operator IS DISTINCT FROM (kind = 'staff') OR is_admin IS DISTINCT FROM false
                         ORDER BY id LIMIT 1000
                     )"
                );
                $audit->record(
                    actorId: null,
                    action: 'backfill.operator_flags',
                    targetType: 'users',
                    targetId: 0,
                    before: null,
                    after: ['updated' => $affected],
                    reason: 'Backfill struktural kind lama; is_admin=false semua baris; bypass hanya diberikan manual dan diaudit.',
                    requestId: (string) Str::ulid(),
                    ip: null,
                    userAgent: null,
                );

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
