<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use App\Modules\Settings\Infrastructure\Http\Controllers\AdminFeatureFlagController;
use App\Modules\Settings\Infrastructure\Http\Controllers\FeatureFlagController;
use App\Modules\Settings\Infrastructure\Http\RequireUuidIdempotencyKey;
use Illuminate\Support\Facades\Route;

Route::get('feature-flags', FeatureFlagController::class)->middleware('rate:public');
Route::get('admin/feature-flags', [AdminFeatureFlagController::class, 'index'])
    ->middleware(['rate:admin', RequireAdminPermission::class.':setting.view']);
Route::patch('admin/feature-flags', [AdminFeatureFlagController::class, 'update'])
    ->middleware(['rate:admin', RequireAdminPermission::class.':setting.update', VerifySessionCsrf::class, RequireUuidIdempotencyKey::class, 'idempotency:adminFeatureFlagsPatch,admin,86400']);
