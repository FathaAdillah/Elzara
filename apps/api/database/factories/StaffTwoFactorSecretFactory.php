<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\StaffTwoFactorSecret;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use Symfony\Component\Clock\NativeClock;

/** @extends Factory<StaffTwoFactorSecret> */
final class StaffTwoFactorSecretFactory extends Factory
{
    protected $model = StaffTwoFactorSecret::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->operator(),
            'secret_encrypted' => Crypt::encryptString(TOTP::generate(new NativeClock)->getSecret()),
            'confirmed_at' => now(),
            'recovery_hashes' => '{}',
        ];
    }

    public function withSecret(string $secret): self
    {
        return $this->state(['secret_encrypted' => Crypt::encryptString($secret)]);
    }

    public function unconfirmed(): self
    {
        return $this->state(['confirmed_at' => null]);
    }
}
