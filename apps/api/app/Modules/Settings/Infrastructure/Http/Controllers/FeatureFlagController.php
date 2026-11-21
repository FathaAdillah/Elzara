<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Http\Controllers;

use App\Modules\Settings\Application\Queries\ListFeatureFlags;
use Illuminate\Http\JsonResponse;

final class FeatureFlagController
{
    public function __invoke(ListFeatureFlags $flags): JsonResponse
    {
        return response()->json(['data' => $flags->publicFlags()])->header('Cache-Control', 'no-store');
    }
}
