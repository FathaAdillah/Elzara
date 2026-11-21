<?php

declare(strict_types=1);

use App\Modules\Orders\Infrastructure\Http\Controllers\PlaceOrderController;
use App\Modules\Settings\Infrastructure\Http\Middleware\EnsureCheckoutEnabled;
use Illuminate\Support\Facades\Route;

Route::post('orders', PlaceOrderController::class)->middleware(['rate:web', EnsureCheckoutEnabled::class]);
