<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Infrastructure\Http;

use App\Modules\PreOrder\Application\PublicBatches;
use App\Platform\Http\StrictValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BatchController
{
    public function index(Request $request, PublicBatches $batches): JsonResponse
    {
        StrictValidation::validate($request, ['limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|string|max:8192']);

        return response()->json($batches->listing($request));
    }

    public function show(Request $request, PublicBatches $batches, string $id): JsonResponse
    {
        StrictValidation::validate($request, []);

        return response()->json($batches->detail($id));
    }
}
