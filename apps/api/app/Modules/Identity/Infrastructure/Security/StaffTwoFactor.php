<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Security;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use OTPHP\TOTP;
use Symfony\Component\Clock\NativeClock;

final class StaffTwoFactor
{
    private const CLOCK_LEEWAY = 29;

    public function isConfirmed(User $user): bool
    {
        return DB::table('staff_2fa_secrets')
            ->where('user_id', $user->getKey())
            ->whereNotNull('confirmed_at')
            ->exists();
    }

    public function consume(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $factor = DB::table('staff_2fa_secrets')
                ->where('user_id', $user->getKey())
                ->whereNotNull('confirmed_at')
                ->selectRaw("id, convert_from(secret_encrypted, 'UTF8') AS encrypted, to_json(recovery_hashes) AS recovery")
                ->lockForUpdate()
                ->first();
            if ($factor === null) {
                return false;
            }

            if (preg_match('/\A[0-9]{6}\z/', $code) === 1) {
                $otp = TOTP::createFromSecret(Crypt::decryptString($factor->encrypted), new NativeClock());
                if (!$otp->verify($code, now()->getTimestamp(), self::CLOCK_LEEWAY)) {
                    return false;
                }

                $digest = hash_hmac('sha256', $code, config('app.key'));

                return Cache::add("admin-totp-used:{$user->getKey()}:{$digest}", true, 90);
            }

            $normalized = strtoupper($code);
            if (preg_match('/\A[A-F0-9]{12}\z/', $normalized) !== 1) {
                return false;
            }

            foreach (json_decode($factor->recovery, true, 8, JSON_THROW_ON_ERROR) as $hash) {
                if (password_get_info($hash)['algoName'] === 'argon2id' && password_verify($normalized, $hash)) {
                    $deleted = DB::update(
                        'UPDATE staff_2fa_secrets SET recovery_hashes = array_remove(recovery_hashes, ?), updated_at = ? WHERE id = ?',
                        [$hash, now(), $factor->id],
                    );

                    return $deleted === 1;
                }
            }

            return false;
        });
    }

    /** @return list<string>|null */
    public function enroll(User $user, TOTP $otp, string $verificationCode): ?array
    {
        if (preg_match('/\A[0-9]{6}\z/', $verificationCode) !== 1
            || !$otp->verify($verificationCode, now()->getTimestamp(), self::CLOCK_LEEWAY)) {
            return null;
        }

        $codes = [];
        while (count($codes) < 10) {
            $code = strtoupper(bin2hex(random_bytes(6)));
            $codes[$code] = $code;
        }

        $hashes = array_map(
            static fn (string $code): string => password_hash($code, PASSWORD_ARGON2ID),
            array_values($codes),
        );
        $placeholders = implode(', ', array_fill(0, count($hashes), '?'));

        return DB::transaction(function () use ($user, $otp, $codes, $hashes, $placeholders): ?array {
            $locked = User::query()->lockForUpdate()->find($user->getKey());
            if ($locked === null || !$locked->canAccessAdmin() || $this->isConfirmed($locked)) {
                return null;
            }

            $encrypted = Crypt::encryptString($otp->getSecret());
            $affected = DB::affectingStatement(
                "INSERT INTO staff_2fa_secrets (user_id, secret_encrypted, confirmed_at, recovery_hashes)
                VALUES (?, convert_to(?, 'UTF8'), CURRENT_TIMESTAMP, ARRAY[{$placeholders}]::text[])
                ON CONFLICT (user_id) DO UPDATE SET secret_encrypted = EXCLUDED.secret_encrypted,
                confirmed_at = EXCLUDED.confirmed_at, recovery_hashes = EXCLUDED.recovery_hashes, updated_at = CURRENT_TIMESTAMP
                WHERE staff_2fa_secrets.confirmed_at IS NULL",
                [$locked->getKey(), $encrypted, ...$hashes],
            );

            return $affected === 1 ? array_values($codes) : null;
        });
    }
}
