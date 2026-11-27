<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\EnsureWebSession;
use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\RequireCustomerSession;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use App\Modules\Sourcing\Infrastructure\Http\ProductRequestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['rate:web', EnsureWebSession::class, RequireCustomerSession::class, VerifySessionCsrf::class])->group(function (): void {
    Route::post('product-requests', [ProductRequestController::class, 'store'])
        ->middleware('idempotency:createProductRequest,web,86400');
    Route::get('me/product-requests', [ProductRequestController::class, 'mine']);
});

Route::get('admin/product-requests', [ProductRequestController::class, 'queue'])
    ->middleware(['rate:admin', RequireAdminPermission::class.':sourcing.view']);
