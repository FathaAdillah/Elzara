<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Http;

use Illuminate\Http\Request;

final class FeatureFlagSubject
{
    public function resolve(Request $request): int|string|null
    {
        $subject = $request->user()?->getAuthIdentifier();

        return is_int($subject) || is_string($subject) ? $subject : null;
    }
}
