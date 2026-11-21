<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<UserSession> */
final class UserSessionFactory extends Factory
{
    protected $model = UserSession::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'session_hash' => hash('sha256', Str::random(40)),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'device_label' => 'Test device',
            'last_seen_at' => now(),
        ];
    }

    public function revoked(): self
    {
        return $this->state(['revoked_at' => now()]);
    }
}
