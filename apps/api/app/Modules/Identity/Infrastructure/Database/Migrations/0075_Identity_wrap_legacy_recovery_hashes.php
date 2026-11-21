<?php

// modul: Identity | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $factors = DB::table('staff_2fa_secrets')
                ->selectRaw('id, to_json(recovery_hashes) AS recovery')
                ->orderBy('id')
                ->lockForUpdate()
                ->cursor();

            foreach ($factors as $factor) {
                /** @var object{id: int, recovery: string} $factor */
                /** @var list<string> $hashes */
                $hashes = json_decode($factor->recovery, true, 8, JSON_THROW_ON_ERROR);
                foreach ($hashes as $hash) {
                    if (preg_match('/\A[0-9a-f]{64}\z/', $hash) === 1) {
                        DB::update(
                            'UPDATE staff_2fa_secrets SET recovery_hashes = array_replace(recovery_hashes, ?, ?), updated_at = ? WHERE id = ?',
                            [$hash, 'legacy-sha256$'.password_hash($hash, PASSWORD_ARGON2ID), now(), $factor->id],
                        );
                    }
                }
            }
        });
    }

    public function down(): void {}
};
