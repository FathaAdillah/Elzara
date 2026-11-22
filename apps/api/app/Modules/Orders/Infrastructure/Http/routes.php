<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\EnsureWebSession;
use App\Modules\Identity\Infrastructure\Http\RequireCustomerSession;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use App\Modules\Orders\Infrastructure\Http\Controllers\PlaceOrderController;
use App\Modules\Orders\Infrastructure\Http\EnsureFakeCheckoutEnabled;
use App\Modules\Settings\Infrastructure\Http\Middleware\EnsureCheckoutEnabled;
use Illuminate\Support\Facades\Route;

Route::middleware(['rate:web', EnsureWebSession::class, EnsureCheckoutEnabled::class, RequireCustomerSession::class, VerifySessionCsrf::class, EnsureFakeCheckoutEnabled::class])->group(function (): void {
    Route::post('orders', [PlaceOrderController::class, 'store'])->middleware('idempotency:createOrder,web,86400');
    Route::post('orders/{order}/fake-payment', [PlaceOrderController::class, 'capture'])->middleware('idempotency:captureFakePayment,web,86400');
});
