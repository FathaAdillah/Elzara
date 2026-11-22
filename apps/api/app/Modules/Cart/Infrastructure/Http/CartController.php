<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Http;

use App\Modules\Cart\Application\CartService;
use App\Platform\Http\StrictValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class CartController
{
    public function __construct(private readonly CartService $cart) {}

    private function actor(): int
    {
        $id = Auth::guard('web')->id();
        abort_unless(is_int($id), 401);

        return $id;
    }

    public function index(): JsonResponse
    {
        return response()->json($this->cart->read($this->actor()))->header('Cache-Control', 'no-store');
    }

    public function store(Request $r): JsonResponse
    {
        $data = StrictValidation::validate($r, ['variant_id' => 'required|string|regex:/^var_[0-9A-Za-z]{20,32}$/', 'quantity' => 'required|integer|min:1|max:99']);

        return response()->json($this->cart->add($this->actor(), $r->string('variant_id')->toString(), $r->integer('quantity')), 201)->header('Cache-Control', 'no-store');
    }

    public function update(Request $r, string $id): JsonResponse
    {
        StrictValidation::validate($r, ['quantity' => 'required|integer|min:1|max:99']);

        return response()->json($this->cart->change($this->actor(), $id, $r->integer('quantity')))->header('Cache-Control', 'no-store');
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json($this->cart->change($this->actor(), $id, null))->header('Cache-Control', 'no-store');
    }
}
