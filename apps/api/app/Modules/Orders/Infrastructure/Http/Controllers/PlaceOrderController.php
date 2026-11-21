<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Http\Controllers;

use App\Modules\Orders\Application\Commands\PlaceOrder;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlaceOrderController
{
    use DispatchesJobs;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{items: array<array-key, mixed>} $data */
        $data = $request->validate(['items' => ['required', 'array', 'min:1']]);
        $subject = $request->user()?->getAuthIdentifier();
        $this->dispatch(new PlaceOrder(is_int($subject) || is_string($subject) ? $subject : null, $data));

        return response()->json(['status' => 'queued'], 202);
    }
}
