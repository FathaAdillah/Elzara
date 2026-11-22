<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Http\Request;

/** Identitas operator + korelasi request yang ikut ke audit log setiap mutasi katalog. */
final class AdminContext
{
    public function __construct(
        public readonly int $actorId,
        public readonly string $reason,
        public readonly string $requestId,
        public readonly ?string $ip,
        public readonly string $userAgent,
    ) {}

    public static function fromRequest(Request $request, string $defaultReason): self
    {
        $reason = $request->input('reason');

        return new self(
            $request->attributes->getInt('admin_actor_id'),
            is_string($reason) && trim($reason) !== '' ? $reason : $defaultReason,
            $request->attributes->getString('request_id'),
            $request->ip(),
            (string) $request->userAgent(),
        );
    }
}
