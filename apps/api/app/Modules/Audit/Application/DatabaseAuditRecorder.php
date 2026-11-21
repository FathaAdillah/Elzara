<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use Illuminate\Support\Facades\DB;

final class DatabaseAuditRecorder implements AuditRecorder
{
    public function record(int $actorId, string $action, string $targetType, int $targetId, array $before, array $after, string $reason, string $requestId, ?string $ip, string $userAgent): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $actorId, 'action' => $action, 'target_type' => $targetType, 'target_id' => $targetId,
            'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'reason' => $reason, 'request_id' => $requestId, 'ip' => $ip, 'user_agent' => mb_substr($userAgent, 0, 255),
        ]);
    }
}
