<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Security\StaffTwoFactor;

final class AdminAuthentication
{
    private const DUMMY_PASSWORD = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';

    public function __construct(private readonly StaffTwoFactor $factors) {}

    public function authenticate(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();
        $passwordHash = $user?->getAuthPassword();
        $matches = password_verify($password, $passwordHash ?? self::DUMMY_PASSWORD);
        abort_unless($user !== null && $passwordHash !== null && $matches, 401);
        abort_unless($user->canAccessAdmin(), 403);

        return $user;
    }

    public function requireConfirmedFactor(User $user): void
    {
        abort_unless($this->factors->isConfirmed($user), 403);
    }

    public function verifyFactor(int $userId, string $code): User
    {
        $user = User::query()->find($userId);
        abort_unless($user !== null && $user->canAccessAdmin() && $this->factors->consume($user, $code), 401);

        return $user;
    }
}
