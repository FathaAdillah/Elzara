<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\AdminAuthController;
use App\Modules\Identity\Infrastructure\Http\VerifyAdminCsrf;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->middleware(VerifyAdminCsrf::class)->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:30,1,admin-login');
    Route::post('2fa/verify', [AdminAuthController::class, 'verify'])
        ->middleware('throttle:30,1,admin-verify');
    Route::get('session', [AdminAuthController::class, 'session']);
    Route::post('logout', [AdminAuthController::class, 'logout']);
});
