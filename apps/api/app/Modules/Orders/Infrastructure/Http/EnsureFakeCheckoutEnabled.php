<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Http;

use App\Modules\Orders\Application\CheckoutProblem;
use App\Modules\Payments\Contracts\FakePaymentAvailability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFakeCheckoutEnabled
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! FakePaymentAvailability::enabled()) {
            CheckoutProblem::raise(409, 'FAKE_PAYMENT_DISABLED');
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
