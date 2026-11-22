<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Memetakan identitas Google/X ke users (Doc 09 user_identities, Doc 11 §4.4 F).
// Akun lama hanya ditautkan bila email-nya sudah diverifikasi Elzara DAN provider menyatakan
// email itu terverifikasi; email mentah provider tidak pernah cukup untuk menggabungkan akun.
final class SocialAccountResolver
{
    public function resolve(
        string $provider,
        string $providerUserId,
        ?string $email,
        bool $emailVerifiedByProvider,
        ?string $name,
        ?User $current,
    ): User {
        return DB::transaction(function () use ($provider, $providerUserId, $email, $emailVerifiedByProvider, $name, $current): User {
            $identity = DB::table('user_identities')
                ->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first(['user_id']);

            /** @var object{user_id: int}|null $identity */
            if ($identity !== null) {
                abort_if($current !== null && $identity->user_id !== $current->getKey(), 409, 'Akun sosial ini sudah tertaut ke akun lain.');
                $user = User::query()->whereKey($identity->user_id)->where('status', 'active')->first();
                abort_unless($user instanceof User, 401);

                return $user;
            }

            if ($current !== null) {
                $this->link($current, $provider, $providerUserId);

                return $current;
            }

            $existing = $email === null ? null : User::withTrashed()->where('email', $email)->first();
            if ($existing !== null) {
                abort_unless(
                    $emailVerifiedByProvider && $existing->email_verified_at !== null && $existing->status === 'active' && ! $existing->trashed(),
                    409,
                    'Email sudah terdaftar. Masuk dengan kata sandi lalu tautkan akun sosial dari profil.',
                );
                $this->link($existing, $provider, $providerUserId);

                return $existing;
            }

            $publicId = (string) Str::ulid();
            $user = new User;
            $user->forceFill([
                'public_id' => $publicId,
                'name' => Str::limit(trim((string) $name) !== '' ? trim((string) $name) : 'Pelanggan Elzara', 120, ''),
                'email' => $email ?? Str::lower($provider.'-'.$publicId).'@users.invalid',
                'status' => 'active',
            ])->save();
            $this->link($user, $provider, $providerUserId);

            return $user->refresh();
        });
    }

    private function link(User $user, string $provider, string $providerUserId): void
    {
        DB::table('user_identities')->insert([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'linked_at' => now(),
        ]);
    }
}
