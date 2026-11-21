<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Http\Middleware;

use App\Modules\Content\Contracts\LocalizedText;
use App\Modules\Settings\Contracts\FeatureFlagQuery;
use App\Platform\Http\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCheckoutEnabled
{
    public function __construct(private FeatureFlagQuery $flags, private LocalizedText $text) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $subject = $request->user()?->getAuthIdentifier();
        if (! $this->flags->enabled('checkout.enabled', is_int($subject) || is_string($subject) ? $subject : null)) {
            $locale = $request->getPreferredLanguage(['id', 'en']) ?? 'id';

            return ApiProblem::make('server', 'checkout-disabled', $this->text->value('checkout.title', 'shop', $locale),
                503, 'CHECKOUT_DISABLED', true, $this->text->value('checkout.disabled', 'shop', $locale))
                ->forRequest($request, $request->attributes->getString('request_id'))->toResponse();
        }

        return $next($request);
    }
}
