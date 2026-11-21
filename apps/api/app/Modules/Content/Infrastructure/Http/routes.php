<?php

declare(strict_types=1);

use App\Modules\Content\Infrastructure\Http\MediaController;
use App\Modules\Identity\Infrastructure\Http\RequireAdminSession;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/media')->middleware(['rate:admin', VerifySessionCsrf::class, RequireAdminSession::class])->group(function (): void {
    Route::post('presign', [MediaController::class, 'presign'])->middleware('idempotency:adminMediaPresign,admin,600');
    Route::post('{media_id}/finalize', [MediaController::class, 'finalize'])->whereNumber('media_id')->middleware('idempotency:adminMediaFinalize,admin');
    Route::post('{media_id}/sign', [MediaController::class, 'sign'])->whereNumber('media_id');
});
