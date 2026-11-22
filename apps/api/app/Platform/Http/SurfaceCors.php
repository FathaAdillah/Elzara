<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Closure;
use Illuminate\Http\Middleware\HandleCors;

final class SurfaceCors extends HandleCors
{
    public function handle($request, Closure $next)
    {
        $previous = config('cors.allowed_origins');
        $origin = SurfacePolicy::origin(config(SurfacePolicy::isAdmin($request) ? 'surfaces.admin_origin' : 'surfaces.storefront_origin'));
        config(['cors.allowed_origins' => $origin !== null && $request->header('Origin') === $origin ? [$origin] : []]);
        try {
            $response = parent::handle($request, $next);
            $response->setVary('Origin', false);

            return $response;
        } finally {
            config(['cors.allowed_origins' => $previous]);
        }
    }
}
