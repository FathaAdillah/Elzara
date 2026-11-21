<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\OtpChallenge;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OtpChallenge> */
final class OtpChallengeFactory extends Factory
{
    protected $model = OtpChallenge::class;

    public const CODE = '123456';

    private static ?string $codeHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => 'email',
            'code_hash' => self::$codeHash ??= password_hash(self::CODE, PASSWORD_ARGON2ID),
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
