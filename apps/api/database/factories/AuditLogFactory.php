<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Audit\Infrastructure\Models\AuditLog;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AuditLog> */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->operator(),
            'action' => 'flag.updated',
            'target_type' => 'feature_flag',
            'target_id' => 1,
            'before' => ['enabled' => false],
            'after' => ['enabled' => true],
            'reason' => 'Factory audit entry',
            'request_id' => (string) Str::ulid(),
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'occurred_at' => now(),
        ];
    }
}
