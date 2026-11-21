<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserConsent> */
final class UserConsentFactory extends Factory
{
    protected $model = UserConsent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => 'terms',
            'policy_version' => 1,
            'granted_at' => now(),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ];
    }
}
