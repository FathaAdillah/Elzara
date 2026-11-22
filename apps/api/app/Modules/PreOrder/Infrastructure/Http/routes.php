<?php

declare(strict_types=1);

use App\Modules\PreOrder\Infrastructure\Http\BatchController;
use Illuminate\Support\Facades\Route;

Route::middleware('rate:public')->group(function (): void {
    Route::get('preorder-batches', [BatchController::class, 'index']);
    Route::get('preorder-batches/{id}', [BatchController::class, 'show']);
});
