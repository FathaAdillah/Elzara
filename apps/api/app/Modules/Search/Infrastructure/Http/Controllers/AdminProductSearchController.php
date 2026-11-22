<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure\Http\Controllers;

use App\Modules\Search\Application\ProductSearch;
use App\Modules\Search\Application\ReindexProductSearch;
use App\Modules\Search\Application\SearchUnavailable;
use App\Platform\Domain\Scalar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AdminProductSearchController
{
    public function __construct(private readonly ProductSearch $search) {}

    public function index(Request $request): JsonResponse
    {
        $input = $request->query();
        foreach (ProductSearch::FACETS as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = array_values(array_filter(explode(',', $input[$field]), static fn (string $value): bool => $value !== ''));
            }
        }
        $rules = [
            'q' => ['sometimes', 'string', 'max:200'],
            'price_min' => ['sometimes', 'integer', 'min:0'],
            'price_max' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ];
        foreach (ProductSearch::FACETS as $field) {
            $rules[$field] = ['sometimes', 'array', 'max:20'];
            $rules[$field.'.*'] = ['string', 'max:120'];
        }
        $data = Validator::make($input, $rules)->validate();
        foreach (['price_min', 'price_max', 'limit'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = Scalar::int($data[$field]);
            }
        }
        /** @var array{q?: string, brand?: list<string>, category?: list<string>, collections?: list<string>, origin_country?: list<string>, fulfillment_modes?: list<string>, status?: list<string>, price_min?: int, price_max?: int, limit?: int, cursor?: string} $data */
        try {
            $result = $this->search->search($data);
        } catch (SearchUnavailable) {
            abort(503, 'Mesin pencari tidak tersedia.', ['Retry-After' => '5']);
        }

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    public function reindex(): JsonResponse
    {
        ReindexProductSearch::dispatch();

        return response()->json(['data' => ['queued' => true, 'queue' => 'imports']], 202);
    }
}
