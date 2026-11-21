<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Commands;

use App\Modules\Identity\Infrastructure\Models\User;

abstract class AccessChange
{
    public function __construct(
        public readonly User $actor,
        public readonly User $target,
        public readonly string $name,
        public readonly string $reason,
        public readonly string $requestId,
        public readonly ?string $ip = null,
        public readonly string $userAgent = '',
        public readonly ?string $scopeType = null,
        public readonly ?int $scopeId = null,
    ) {}

    abstract public function handle(ChangeUserAccess $access): void;
}
