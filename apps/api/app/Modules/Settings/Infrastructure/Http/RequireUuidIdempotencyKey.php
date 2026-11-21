<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Http;

use App\Platform\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireUuidIdempotencyKey
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key !== null && ! preg_match('/\\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\\z/i', $key)) {
            return ApiProblem::make('idempotency', 'key-invalid', __('messages.idempotency.invalid'), 400, 'IDEMPOTENCY_KEY_INVALID')
                ->forRequest($request, $request->attributes->getString('request_id'))->toResponse();
        }

        return $next($request);
    }
}
