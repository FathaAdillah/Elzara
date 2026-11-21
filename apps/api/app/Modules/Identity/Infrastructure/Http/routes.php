<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\AdminAccessController;
use App\Modules\Identity\Infrastructure\Http\AdminAuthController;
use App\Modules\Identity\Infrastructure\Http\EnsureWebSession;
use App\Modules\Identity\Infrastructure\Http\MySessionsController;
use App\Modules\Identity\Infrastructure\Http\RequireAccessIdempotencyKey;
use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\SocialAuthController;
use App\Modules\Identity\Infrastructure\Http\StorefrontAuthController;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/auth')->middleware(['rate:admin', VerifySessionCsrf::class])->group(function (): void {
    Route::post('login', [AdminAuthController::class, 'login'])
        ->middleware('rate:admin-auth');
    Route::post('2fa/verify', [AdminAuthController::class, 'verify'])
        ->middleware('rate:admin-auth');
    Route::get('session', [AdminAuthController::class, 'session']);
    Route::post('logout', [AdminAuthController::class, 'logout']);
});

Route::prefix('admin/users/{user}')->middleware('rate:admin')->group(function (): void {
    Route::get('access', [AdminAccessController::class, 'show'])->middleware(RequireAdminPermission::class.':user.view');
    Route::middleware([RequireAdminPermission::class.':user.update', VerifySessionCsrf::class, RequireAccessIdempotencyKey::class])->group(function (): void {
        Route::post('permissions', [AdminAccessController::class, 'grant'])->middleware('idempotency:adminUserPermissionGrant,admin,86400');
        Route::delete('permissions/{permission}', [AdminAccessController::class, 'revokePermission'])->middleware('idempotency:adminUserPermissionRevoke,admin,86400');
        Route::post('roles', [AdminAccessController::class, 'assignRole'])->middleware('idempotency:adminUserRoleAssign,admin,86400');
        Route::delete('roles/{role}', [AdminAccessController::class, 'revokeRole'])->middleware('idempotency:adminUserRoleRevoke,admin,86400');
    });
});

// Storefront memakai guard `web` dan cookie elzara_web_session; CSRF diperiksa untuk setiap mutasi.
Route::middleware(['rate:web', EnsureWebSession::class, VerifySessionCsrf::class])->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [StorefrontAuthController::class, 'register']);
        Route::post('login', [StorefrontAuthController::class, 'login']);
        Route::post('logout', [StorefrontAuthController::class, 'logout']);
        Route::post('otp/request', [StorefrontAuthController::class, 'requestOtp']);
        Route::post('otp/verify', [StorefrontAuthController::class, 'verifyOtp']);
        Route::post('password/reset-request', [StorefrontAuthController::class, 'requestPasswordReset']);
        Route::post('password/reset-confirm', [StorefrontAuthController::class, 'confirmPasswordReset']);
        Route::get('social/{provider}/redirect', [SocialAuthController::class, 'redirect'])->whereIn('provider', ['google', 'x']);
        Route::get('social/{provider}/callback', [SocialAuthController::class, 'callback'])->whereIn('provider', ['google', 'x']);
    });

    Route::get('me/sessions', [MySessionsController::class, 'index']);
    Route::delete('me/sessions/{sessionId}', [MySessionsController::class, 'destroy'])->where('sessionId', '[0-9a-f]{64}');
});
