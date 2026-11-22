<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DatabaseAuditRecorder implements AuditRecorder
{
    public function record(?int $actorId, string $action, string $targetType, int $targetId, ?array $before, array $after, string $reason, string $requestId, ?string $ip, ?string $userAgent): void
    {
        $this->recordOn(DB::connection(), $actorId, $action, $targetType, $targetId, $before, $after, $reason, $requestId, $ip, $userAgent);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    public function recordOn(ConnectionInterface $connection, ?int $actorId, string $action, string $targetType, int $targetId, ?array $before, array $after, string $reason, string $requestId, ?string $ip = null, ?string $userAgent = null): void
    {
        if (strlen($requestId) > 26) {
            $after['_context'] = ['request_id' => $requestId];
            $requestId = (string) Str::ulid();
        }
        $connection->table('audit_logs')->insert([
            'actor_id' => $actorId, 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason, 'request_id' => $requestId, 'ip' => $ip, 'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
        ]);
    }
}
