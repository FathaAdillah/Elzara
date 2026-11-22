<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Http\Controllers;

use App\Modules\Orders\Application\Commands\PlaceOrder;
use App\Modules\Orders\Application\Commands\ResolveFakeCheckout;
use App\Platform\Http\StrictValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class PlaceOrderController
{
    public function store(Request $request, PlaceOrder $order): JsonResponse
    {
        StrictValidation::validate($request, [
            'checkout_session_id' => 'required|string|regex:/^cs_[0-9A-Za-z]{20,32}$/',
            'shipping_address_id' => 'required|string|regex:/^addr_[0-9A-Za-z]{20,32}$/',
            'note' => 'nullable|string|max:1000',
        ]);
        $note = $request->input('note');
        $result = $order->handle($this->actor(), (string) $request->string('checkout_session_id'), (string) $request->string('shipping_address_id'), is_string($note) ? $note : null);

        return response()->json($result, 201);
    }

    public function capture(Request $request, string $order, ResolveFakeCheckout $checkout): JsonResponse
    {
        StrictValidation::validate($request, ['payment_intent_id' => 'required|string|regex:/^pi_[0-9A-Za-z]{20,32}$/']);

        return response()->json($checkout->capture($this->actor(), $order, (string) $request->string('payment_intent_id')));
    }

    private function actor(): int
    {
        $id = Auth::guard('web')->id();
        abort_unless(is_int($id), 401);

        return $id;
    }
}
