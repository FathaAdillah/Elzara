<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Http;

use App\Modules\Cart\Application\CheckoutService;
use App\Platform\Http\StrictValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CheckoutController
{
    public function __construct(private readonly CheckoutService $checkout) {}

    private function actor(): int
    {
        $id = Auth::guard('web')->id();
        abort_unless(is_int($id), 401);

        return $id;
    }

    public function store(Request $r): JsonResponse
    {
        StrictValidation::validate($r, ['shipping_address_id' => 'required|string', 'expected_items_hash' => 'required|string|regex:/^sha256:[a-f0-9]{64}$/']);
        $s = $this->checkout->create($this->actor(), $r->string('shipping_address_id')->toString(), $r->string('expected_items_hash')->toString());

        return response()->json($this->checkout->read($s), 201)->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        return response()->json($this->checkout->read($this->checkout->owned($this->actor(), $id)))->header('Cache-Control', 'no-store');
    }

    public function options(Request $r, string $id): JsonResponse
    {
        StrictValidation::validate($r, []);

        return response()->json($this->checkout->options($this->actor(), $id))->header('Cache-Control', 'no-store');
    }

    public function selection(Request $r, string $id): JsonResponse
    {
        StrictValidation::validate($r, ['selections' => 'required|array|min:1']);

        return response()->json($this->checkout->select($this->actor(), $id, $r->array('selections')))->header('Cache-Control', 'no-store');
    }
}
