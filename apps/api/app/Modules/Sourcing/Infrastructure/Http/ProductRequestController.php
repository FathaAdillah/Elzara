<?php

declare(strict_types=1);

namespace App\Modules\Sourcing\Infrastructure\Http;

use App\Modules\Sourcing\Application\ProductRequests;
use App\Platform\Http\StrictValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** @phpstan-import-type Fields from ProductRequests */
final class ProductRequestController
{
    public function store(Request $request, ProductRequests $requests): JsonResponse
    {
        /** @var Fields $fields */
        $fields = StrictValidation::validate($request, [
            'title' => 'required|string|min:5|max:200',
            'quantity' => 'required|integer:strict|min:1|max:999',
            'target_price' => 'sometimes|array:amount_minor,currency|required_array_keys:amount_minor,currency',
            'target_price.amount_minor' => 'required_with:target_price|integer:strict|min:0|max:999999999999',
            'target_price.currency' => 'required_with:target_price|string|in:IDR',
            'brand' => 'nullable|string|max:120',
            'series' => 'nullable|string|max:120',
            'character_name' => 'nullable|string|max:120',
            'target_url' => 'nullable|string|max:500|url:http,https',
            'origin_country' => 'nullable|string|regex:/^[A-Z]{2}$/D',
            'preferred_mode' => 'nullable|string|in:ready_stock,preorder',
            'notes' => 'nullable|string|max:2000',
        ]);

        return $this->privateJson($requests->submit($this->customer(), $fields), 201);
    }

    public function mine(Request $request, ProductRequests $requests): JsonResponse
    {
        StrictValidation::validate($request, ['cursor' => 'string|max:500', 'limit' => 'integer|min:1|max:100']);

        return $this->privateJson($requests->forCustomer($request, $this->customer()));
    }

    public function queue(Request $request, ProductRequests $requests): JsonResponse
    {
        /** @var array{status?: ?string, account?: ?string} $fields */
        $fields = StrictValidation::validate($request, [
            'status' => 'nullable|string|in:'.implode(',', ProductRequests::STATUSES),
            'account' => 'nullable|string|email:rfc|max:254',
            'cursor' => 'string|max:500',
            'limit' => 'integer|min:1|max:100',
        ]);

        return $this->privateJson($requests->forStaff($request, $fields['status'] ?? null, $fields['account'] ?? null));
    }

    private function customer(): int
    {
        $id = Auth::guard('web')->id();
        abort_unless(is_int($id), 401);

        return $id;
    }

    /** @param array<string, mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'private, no-store']);
    }
}
