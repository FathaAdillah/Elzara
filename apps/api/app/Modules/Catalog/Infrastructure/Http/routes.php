<?php

declare(strict_types=1);

use App\Modules\Catalog\Application\CatalogResources;
use App\Modules\Catalog\Infrastructure\Http\Controllers\AdminCatalogCsvController;
use App\Modules\Catalog\Infrastructure\Http\Controllers\AdminCatalogEntityController;
use App\Modules\Catalog\Infrastructure\Http\Controllers\AdminProductController;
use App\Modules\Catalog\Infrastructure\Http\PublicCatalogController;
use App\Modules\Identity\Infrastructure\Http\RequireAdminPermission;
use App\Modules\Identity\Infrastructure\Http\VerifySessionCsrf;
use App\Modules\Settings\Infrastructure\Http\RequireUuidIdempotencyKey;
use Illuminate\Support\Facades\Route;

$view = ['rate:admin', RequireAdminPermission::class.':catalog.view'];
$mutate = static fn (string $permission, string $operation): array => [
    'rate:admin', RequireAdminPermission::class.':catalog.'.$permission, VerifySessionCsrf::class, RequireUuidIdempotencyKey::class, "idempotency:{$operation},admin,86400",
];

Route::prefix('admin/catalog')->whereNumber('id')->group(function () use ($view, $mutate): void {
    foreach (CatalogResources::names() as $resource) {
        $name = str_replace(' ', '', ucwords(str_replace('-', ' ', $resource)));
        Route::get($resource, [AdminCatalogEntityController::class, 'index'])->defaults('resource', $resource)->middleware($view);
        Route::post($resource, [AdminCatalogEntityController::class, 'store'])->defaults('resource', $resource)->middleware($mutate('create', "adminCatalog{$name}Create"));
        Route::get("{$resource}/{id}", [AdminCatalogEntityController::class, 'show'])->defaults('resource', $resource)->middleware($view);
        Route::patch("{$resource}/{id}", [AdminCatalogEntityController::class, 'update'])->defaults('resource', $resource)->middleware($mutate('update', "adminCatalog{$name}Update"));
        Route::delete("{$resource}/{id}", [AdminCatalogEntityController::class, 'destroy'])->defaults('resource', $resource)->middleware($mutate('delete', "adminCatalog{$name}Delete"));
    }
    Route::get('price-lists/{id}/entries', [AdminProductController::class, 'priceListEntries'])->middleware($view);
    Route::put('price-lists/{id}/entries', [AdminProductController::class, 'replacePriceListEntries'])->middleware($mutate('update', 'adminCatalogPriceListEntriesReplace'));

    Route::get('products', [AdminProductController::class, 'index'])->middleware($view);
    Route::post('products', [AdminProductController::class, 'store'])->middleware($mutate('create', 'adminCatalogProductsCreate'));
    Route::get('products/export', [AdminCatalogCsvController::class, 'export'])->middleware(['rate:admin', RequireAdminPermission::class.':catalog.view']);
    Route::post('products/import', [AdminCatalogCsvController::class, 'import'])
        ->middleware(['rate:admin', RequireAdminPermission::class.':catalog.create', RequireAdminPermission::class.':catalog.update', VerifySessionCsrf::class]);
    Route::get('products/{id}', [AdminProductController::class, 'show'])->middleware($view);
    Route::patch('products/{id}', [AdminProductController::class, 'update'])->middleware($mutate('update', 'adminCatalogProductsUpdate'));
    Route::delete('products/{id}', [AdminProductController::class, 'destroy'])->middleware($mutate('delete', 'adminCatalogProductsDelete'));
    Route::post('products/{id}/variants', [AdminProductController::class, 'storeVariant'])->middleware($mutate('create', 'adminCatalogVariantsCreate'));
    Route::patch('variants/{id}', [AdminProductController::class, 'updateVariant'])->middleware($mutate('update', 'adminCatalogVariantsUpdate'));
    Route::delete('variants/{id}', [AdminProductController::class, 'destroyVariant'])->middleware($mutate('delete', 'adminCatalogVariantsDelete'));
});

Route::middleware('rate:public')->group(function (): void {
    Route::get('products', [PublicCatalogController::class, 'index']);
    Route::get('products/{slug}', [PublicCatalogController::class, 'show'])->where('slug', '[a-z0-9-]{1,120}');
    Route::get('categories', [PublicCatalogController::class, 'categories']);
});
