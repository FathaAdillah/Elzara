<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Application;

use App\Platform\Http\ApiProblem;
use Illuminate\Http\Exceptions\HttpResponseException;

final class BatchProblem
{
    public static function raise(string $code): never
    {
        $request = request();
        $slug = match ($code) {
            'BATCH_NOT_OPEN' => 'not-open',
            'BATCH_QUOTA_EXHAUSTED' => 'quota-exhausted',
            default => 'account-limit-exceeded',
        };
        throw new HttpResponseException(ApiProblem::make('batch', $slug, $code, 422, $code)
            ->forRequest($request, $request->attributes->getString('request_id'))->toResponse());
    }
}
