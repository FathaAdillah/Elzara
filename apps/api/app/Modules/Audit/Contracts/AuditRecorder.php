<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

interface AuditRecorder
{
    /** @param array<string, mixed>|null $before
     * @param  array<string, mixed>  $after
     */
    public function record(?int $actorId, string $action, string $targetType, int $targetId, ?array $before, array $after, string $reason, string $requestId, ?string $ip, ?string $userAgent): void;
}
