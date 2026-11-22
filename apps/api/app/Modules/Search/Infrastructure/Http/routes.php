<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use App\Modules\Search\Infrastructure\Http\Controllers\AdminProductSearchController;
use App\Modules\Settings\Infrastructure\Http\RequireUuidIdempotencyKey;
use Illuminate\Support\Facades\Route;

Route::get('admin/catalog/search', [AdminProductSearchController::class, 'index'])
    ->middleware(['rate:admin', RequireAdminPermission::class.':catalog.view']);
Route::post('admin/catalog/search/reindex', [AdminProductSearchController::class, 'reindex'])
    ->middleware(['rate:admin', RequireAdminPermission::class.':catalog.update', VerifySessionCsrf::class, RequireUuidIdempotencyKey::class, 'idempotency:adminCatalogSearchReindex,admin,86400']);
