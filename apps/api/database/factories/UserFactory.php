<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<User> */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public const PASSWORD = 'password';

    private static ?string $passwordHash = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'name' => fake()->name(),
            'email' => Str::lower(Str::ulid().'@example.test'),
            'email_verified_at' => now(),
            'password_hash' => self::$passwordHash ??= password_hash(self::PASSWORD, PASSWORD_ARGON2ID),
            'is_operator' => false,
            'is_admin' => false,
            'status' => 'active',
            'locale' => 'id',
            'timezone' => 'Asia/Jakarta',
        ];
    }

    public function operator(): self
    {
        return $this->state(['is_operator' => true]);
    }

    public function adminBypass(): self
    {
        return $this->state(['is_operator' => true, 'is_admin' => true]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => 'suspended']);
    }

    public function unverified(): self
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function withProfile(): self
    {
        return $this->afterCreating(function (User $user): void {
            CustomerProfileFactory::new()->create(['user_id' => $user->id]);
        });
    }
}
