<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\User;

return [
    'local_admin_password_only_email' => env('ELZARA_LOCAL_ADMIN_PASSWORD_ONLY_EMAIL'),
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'admin' => ['driver' => 'session', 'provider' => 'users'],
    ],
    'providers' => [
        'users' => ['driver' => 'eloquent', 'model' => User::class],
    ],
];
