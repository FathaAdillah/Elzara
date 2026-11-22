<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Platform\StateMachine\Axis;
use App\Platform\StateMachine\TransitionSink;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AuditLogTransitionSink implements TransitionSink
{
    public function __construct(private readonly DatabaseAuditRecorder $audit) {}

    public function record(ConnectionInterface $connection, string $table, int $aggregateId, Axis $axis, string $from, string $to, string $reason, ?int $actorId = null): void
    {
        $target = match ($table) {
            'orders' => 'order',
            default => throw new InvalidArgumentException('Aggregate belum terdaftar untuk audit transisi.'),
        };
        $requestId = Context::get('request_id');
        $this->audit->recordOn(
            $connection,
            $actorId,
            'order.state.transition',
            $target,
            $aggregateId,
            [$axis->value => $from],
            [$axis->value => $to],
            $reason,
            is_string($requestId) ? $requestId : (string) Str::ulid(),
        );
    }
}
