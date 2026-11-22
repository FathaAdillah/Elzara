<?php

declare(strict_types=1);

namespace App\Modules\Orders\Application;

use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;

final class CheckoutProblem
{
    public static function raise(int $status, string $code): never
    {
        $slug = strtolower(str_replace('_', '-', $code));
        $request = request();
        throw new HttpResponseException(ApiProblem::make('checkout', $slug, $code, $status, $code)
            ->forRequest($request, $request->attributes->getString('request_id'))->toResponse());
    }
}
