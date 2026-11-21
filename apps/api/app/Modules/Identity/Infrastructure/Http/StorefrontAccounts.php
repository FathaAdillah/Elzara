<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Platform\Http\WireTime;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\OutboxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Penulisan akun storefront: registrasi (+ event UserRegistered lewat outbox) dan reset kata sandi.
final class StorefrontAccounts
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    public function register(string $name, string $email, string $password, string $locale): User
    {
        return DB::transaction(function () use ($name, $email, $password, $locale): User {
            abort_if(User::withTrashed()->where('email', $email)->exists(), 409, 'Email sudah terdaftar.');

            $user = new User;
            $user->forceFill([
                'public_id' => (string) Str::ulid(),
                'name' => $name,
                'email' => $email,
                'password_hash' => Hash::make($password),
                'status' => 'active',
                'locale' => $locale,
            ])->save();
            $user->refresh();

            $this->outbox->record(new DomainEvent('UserRegistered', 'user', $user->id, [
                'user_id' => $user->public_id,
                'email_verified_at' => null,
                'locale' => $locale,
                'created_at' => WireTime::format(CarbonImmutable::now()),
            ]));

            return $user;
        });
    }

    // Bila kepemilikan email dibuktikan di luar sesi pendaftar, kata sandi yang dipasang
    // sebelum verifikasi dan semua sesinya dicabut: pemilik kotak surat memegang akun sendirian.
    public function markEmailVerified(User $user, bool $revokeExisting): void
    {
        DB::transaction(function () use ($user, $revokeExisting): void {
            $user->forceFill(['email_verified_at' => now()] + ($revokeExisting ? ['password_hash' => null] : []))->save();
            if ($revokeExisting) {
                $this->revokeSessions($user);
            }
        });
    }

    // Kata sandi baru mencabut seluruh sesi akun, termasuk sesi di perangkat lain. Token reset
    // dikirim lewat email, jadi memakainya juga membuktikan kepemilikan kotak surat.
    public function resetPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password_hash' => Hash::make($password), 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $this->revokeSessions($user);
        });
    }

    private function revokeSessions(User $user): void
    {
        DB::table('user_sessions')
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
