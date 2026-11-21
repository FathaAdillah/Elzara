<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\AdminAuthController;
use App\Modules\Identity\Infrastructure\Http\VerifyAdminCsrf;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->middleware(['rate:admin', VerifyAdminCsrf::class])->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])
        ->middleware('rate:admin-auth');
    Route::post('2fa/verify', [AdminAuthController::class, 'verify'])
        ->middleware('rate:admin-auth');
    Route::get('session', [AdminAuthController::class, 'session']);
    Route::post('logout', [AdminAuthController::class, 'logout']);
});
