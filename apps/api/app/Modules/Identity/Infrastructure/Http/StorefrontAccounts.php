<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http;

use App\Modules\Identity\Infrastructure\Models\User;
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
                'created_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            ]));

            return $user;
        });
    }

    // Kata sandi baru mencabut seluruh sesi akun, termasuk sesi di perangkat lain.
    public function resetPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
            DB::table('user_sessions')
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);
        });
    }
}
