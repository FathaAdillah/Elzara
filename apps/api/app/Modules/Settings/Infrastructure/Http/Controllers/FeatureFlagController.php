<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Http\Controllers;

use App\Modules\Settings\Application\Queries\ListFeatureFlags;
use App\Modules\Settings\Infrastructure\Http\FeatureFlagSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FeatureFlagController
{
    public function __invoke(Request $request, ListFeatureFlags $flags, FeatureFlagSubject $subject): JsonResponse
    {
        return response()->json(['data' => $flags->publicFlags($subject->resolve($request))])->header('Cache-Control', 'no-store');
    }
}
