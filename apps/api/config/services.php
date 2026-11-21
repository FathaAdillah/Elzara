<?php

declare(strict_types=1);

// Kredensial OAuth storefront. Redirect URI harus didaftarkan persis di konsol provider.
return [
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'https://localhost'), '/').'/api/v1/auth/social/google/callback',
    ],
    // Provider X memakai driver `x` bawaan laravel/socialite (OAuth 2.0 + PKCE).
    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect' => env('X_REDIRECT_URI') ?: rtrim((string) env('APP_URL', 'https://localhost'), '/').'/api/v1/auth/social/x/callback',
    ],
];
