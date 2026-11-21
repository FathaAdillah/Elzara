<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// OTP dan token reset sekali-pakai di otp_challenges. Rahasia di-hash Argon2id (keputusan
// owner per Doc 09), jadi token reset membawa id challenge agar baris bisa ditemukan.
final class OneTimeSecrets
{
    public const OTP_TTL_MINUTES = 10;

    public const RESET_TTL_MINUTES = 60;

    public const MAX_ATTEMPTS = 5;

    private const RESET_CHANNEL = 'email';

    private const RESET_PURPOSE = 'reset_password';

    /** @return array{0: string, 1: CarbonImmutable} */
    public function issueOtp(User $user, string $channel, string $purpose): array
    {
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $expiresAt = CarbonImmutable::now()->addMinutes(self::OTP_TTL_MINUTES);

        DB::transaction(function () use ($user, $channel, $purpose, $code, $expiresAt): void {
            $this->retire($user, $purpose);
            DB::table('otp_challenges')->insert($this->row($user, $channel, $purpose, $code, $expiresAt));
        });

        return [$code, $expiresAt];
    }

    public function consumeOtp(User $user, string $purpose, string $code): bool
    {
        return DB::transaction(function () use ($user, $purpose, $code): bool {
            $challenge = DB::table('otp_challenges')
                ->where('user_id', $user->getKey())
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'code_hash']);

            /** @var object{id: int, code_hash: string}|null $challenge */
            return $challenge !== null && $this->settle($challenge, $code);
        });
    }

    /** @return array{0: string, 1: CarbonImmutable} */
    public function issueResetToken(User $user): array
    {
        $secret = Str::random(40);
        $expiresAt = CarbonImmutable::now()->addMinutes(self::RESET_TTL_MINUTES);

        $id = DB::transaction(function () use ($user, $secret, $expiresAt): int {
            $this->retire($user, self::RESET_PURPOSE);

            return (int) DB::table('otp_challenges')->insertGetId(
                $this->row($user, self::RESET_CHANNEL, self::RESET_PURPOSE, $secret, $expiresAt),
            );
        });

        return [$id.'.'.$secret, $expiresAt];
    }

    public function consumeResetToken(string $token): ?int
    {
        if (preg_match('/^([1-9][0-9]{0,18})\.([A-Za-z0-9]{16,100})$/', $token, $parts) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($parts): ?int {
            $challenge = DB::table('otp_challenges')
                ->where('id', (int) $parts[1])
                ->where('purpose', self::RESET_PURPOSE)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', self::MAX_ATTEMPTS)
                ->lockForUpdate()
                ->first(['id', 'code_hash', 'user_id']);

            /** @var object{id: int, code_hash: string, user_id: int}|null $challenge */
            return $challenge !== null && $this->settle($challenge, $parts[2]) ? $challenge->user_id : null;
        });
    }

    /** @param object{id: int, code_hash: string} $challenge */
    private function settle(object $challenge, string $secret): bool
    {
        $valid = password_verify($secret, $challenge->code_hash);
        DB::table('otp_challenges')->where('id', $challenge->id)->update($valid
            ? ['consumed_at' => now(), 'updated_at' => now()]
            : ['attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        return $valid;
    }

    private function retire(User $user, string $purpose): void
    {
        DB::table('otp_challenges')
            ->where('user_id', $user->getKey())
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function row(User $user, string $channel, string $purpose, string $secret, CarbonImmutable $expiresAt): array
    {
        return [
            'user_id' => $user->getKey(),
            'channel' => $channel,
            'code_hash' => password_hash($secret, PASSWORD_ARGON2ID),
            'purpose' => $purpose,
            'attempts' => 0,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
