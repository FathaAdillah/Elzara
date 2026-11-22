<?php

declare(strict_types=1);
use App\Modules\Cart\Infrastructure\Http\CartController;
use App\Modules\Cart\Infrastructure\Http\CheckoutController;
use App\Modules\Identity\Infrastructure\Http\EnsureWebSession;
use App\Modules\Identity\Infrastructure\Http\RequireCustomerSession;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use Illuminate\Support\Facades\Route;

Route::middleware(['rate:web', EnsureWebSession::class, RequireCustomerSession::class, VerifySessionCsrf::class])->group(function (): void {
    Route::get('cart', [CartController::class, 'index']);
    Route::post('cart/items', [CartController::class, 'store'])->middleware('idempotency:addCartItem,web,86400');
    Route::patch('cart/items/{id}', [CartController::class, 'update'])->middleware('idempotency:updateCartItem,web,86400');
    Route::delete('cart/items/{id}', [CartController::class, 'destroy'])->middleware('idempotency:deleteCartItem,web,86400');
    Route::post('checkout/sessions', [CheckoutController::class, 'store'])->middleware('idempotency:createCheckoutSession,web,86400');
    Route::get('checkout/sessions/{id}', [CheckoutController::class, 'show']);
    Route::post('checkout/sessions/{id}/shipping-options', [CheckoutController::class, 'options']);
});
