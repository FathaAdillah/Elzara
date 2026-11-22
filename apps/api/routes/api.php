<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('internal')->middleware('internal')->group(function (): void {});

// Bootstrap Laravel menambahkan prefix /api/v1. Modul Identity mendaftarkan
// login, verifikasi 2FA, pemeriksaan sesi, dan logout admin di sini.
require base_path('app/Modules/Identity/Infrastructure/Http/routes.php');
require base_path('app/Modules/Catalog/Infrastructure/Http/routes.php');
require base_path('app/Modules/Content/Infrastructure/Http/routes.php');
require base_path('app/Modules/Settings/Infrastructure/Http/routes.php');
require base_path('app/Modules/Orders/Infrastructure/Http/routes.php');
require base_path('app/Modules/Search/Infrastructure/Http/routes.php');
require base_path('app/Modules/Cart/Infrastructure/Http/routes.php');
require base_path('app/Modules/PreOrder/Infrastructure/Http/routes.php');
