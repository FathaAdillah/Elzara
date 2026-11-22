<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Commands\BackfillOperatorFlags;
use App\Modules\Identity\Infrastructure\Console\CreateAdminOperator;
use App\Modules\Identity\Infrastructure\Console\EnrollAdminTwoFactor;
use App\Modules\Identity\Infrastructure\Http\SelectAdminSession;
use App\Modules\Search\Infrastructure\Console\ReindexProductsCommand;
use App\Platform\Http\ApiProblemRenderer;
use App\Platform\Http\ApiResponseHeaders;
use App\Platform\Http\AttachRequestId;
use App\Platform\Http\IdempotencyGuard;
use App\Platform\Http\InternalAccess;
use App\Platform\Http\RateLimit;
use App\Platform\Http\TraceContext;
use App\Platform\Observability\ObservabilityServiceProvider;
use App\Platform\Observability\RecordRequestMetrics;
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
    ->withCommands([BackfillOperatorFlags::class, CreateAdminOperator::class, EnrollAdminTwoFactor::class, ReindexProductsCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([RecordRequestMetrics::class, AttachRequestId::class, TraceContext::class, SelectAdminSession::class, ApiResponseHeaders::class]);

        $middleware->statefulApi();
        $middleware->alias(['idempotency' => IdempotencyGuard::class, 'internal' => InternalAccess::class, 'rate' => RateLimit::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(ObservabilityServiceProvider::reportStorageError(...));
        $exceptions->render(
            fn (Throwable $exception, Request $request) => app(ApiProblemRenderer::class)->render($exception, $request)
        );
    })->create();
