<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Console\CreateAdminOperator;
use App\Modules\Identity\Infrastructure\Console\EnrollAdminTwoFactor;
use App\Modules\Identity\Infrastructure\Http\SelectAdminSession;
use App\Platform\Http\ApiProblemRenderer;
use App\Platform\Http\AttachRequestId;
use App\Platform\Http\IdempotencyGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([CreateAdminOperator::class, EnrollAdminTwoFactor::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([AttachRequestId::class, SelectAdminSession::class]);

        $middleware->statefulApi();
        $middleware->alias(['idempotency' => IdempotencyGuard::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (Throwable $exception, Request $request) => app(ApiProblemRenderer::class)->render($exception, $request)
        );
    })->create();
