<?php

declare(strict_types=1);

use App\Modules\Catalog\Infrastructure\Http\PublicCatalogController;
use Illuminate\Support\Facades\Route;

Route::middleware('rate:public')->group(function (): void {
    Route::get('products', [PublicCatalogController::class, 'index']);
    Route::get('products/{slug}', [PublicCatalogController::class, 'show'])->where('slug', '[a-z0-9-]{1,120}');
    Route::get('categories', [PublicCatalogController::class, 'categories']);
});
