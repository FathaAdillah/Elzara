<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Http;

use App\Modules\Catalog\Application\PublicCatalog;
use App\Platform\Http\StrictValidation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicCatalogController
{
    public function __construct(private readonly PublicCatalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $filters = StrictValidation::validate($request, [
            'limit' => 'sometimes|integer|min:1|max:100', 'cursor' => 'sometimes|string|max:8192',
            'q' => 'sometimes|string|min:1|max:200',
            'kind' => 'sometimes|in:rts,pre_order,request_order',
            'category' => 'sometimes|string|regex:/\A[a-z0-9-]{1,80}\z/',
            'collection' => 'sometimes|string|regex:/\A[a-z0-9-]{1,80}\z/',
            'brand' => 'sometimes|string|regex:/\A[a-z0-9-]{1,80}\z/',
            'price_min' => 'sometimes|integer|min:0',
            'price_max' => 'sometimes|integer|min:0'.($request->has('price_min') ? '|gte:price_min' : ''),
            'in_stock' => 'sometimes|in:true,false,1,0',
            'sort' => 'sometimes|in:'.($request->filled('q') ? 'newest,price_asc,price_desc,relevance' : 'newest,price_asc,price_desc'),
        ]);

        return response()->json($this->catalog->listing($request, $filters))->header('Cache-Control', 'public, max-age=30');
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        StrictValidation::validate($request, []);

        return response()->json($this->catalog->detail($slug))->header('Cache-Control', 'public, max-age=30');
    }

    public function categories(Request $request): JsonResponse
    {
        StrictValidation::validate($request, []);

        return response()->json(['data' => $this->catalog->categories()])->header('Cache-Control', 'public, max-age=30');
    }
}
