<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Http\Controllers;

use App\Modules\Catalog\Application\AdminContext;
use App\Modules\Catalog\Application\CatalogInput;
use App\Modules\Catalog\Application\ProductAdminService;
use App\Modules\Catalog\Application\ProductRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminProductController
{
    public function __construct(private readonly ProductAdminService $products) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->products->list($request))->header('Cache-Control', 'no-store');
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->products->show($id)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $data = CatalogInput::validate($request->all(), ProductRules::product());
        $id = $this->products->create($data, AdminContext::fromRequest($request, 'Admin membuat produk'));

        return response()->json(['data' => $this->products->show($id)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = CatalogInput::validate($request->all(), ProductRules::product($id));
        $this->products->update($id, self::int($data['version'] ?? null), $data, AdminContext::fromRequest($request, 'Admin mengubah produk'));

        return response()->json(['data' => $this->products->show($id)]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->products->delete($id, AdminCatalogEntityController::version($request), AdminContext::fromRequest($request, 'Admin menghapus produk'));

        return response()->noContent();
    }

    public function storeVariant(Request $request, int $id): JsonResponse
    {
        $data = CatalogInput::validate($request->all(), ProductRules::variant());

        return response()->json(['data' => $this->products->createVariant($id, $data, AdminContext::fromRequest($request, 'Admin membuat varian'))], 201);
    }

    public function updateVariant(Request $request, int $id): JsonResponse
    {
        $data = CatalogInput::validate($request->all(), ProductRules::variant($id));

        return response()->json(['data' => $this->products->updateVariant($id, self::int($data['version'] ?? null), $data, AdminContext::fromRequest($request, 'Admin mengubah varian'))]);
    }

    public function destroyVariant(Request $request, int $id): Response
    {
        $this->products->deleteVariant($id, AdminCatalogEntityController::version($request), AdminContext::fromRequest($request, 'Admin menghapus varian'));

        return response()->noContent();
    }

    public function priceListEntries(int $id): JsonResponse
    {
        return response()->json(['data' => $this->products->priceListEntries($id)])->header('Cache-Control', 'no-store');
    }

    public function replacePriceListEntries(Request $request, int $id): JsonResponse
    {
        $data = CatalogInput::validate($request->all(), [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'string', 'min:5', 'max:320'],
            'entries' => ['present', 'array', 'max:5000'],
            'entries.*' => ['array:variant_id,price'],
            'entries.*.variant_id' => ['required', 'integer', 'distinct', Rule::exists('product_variants', 'id')->whereNull('deleted_at')],
            'entries.*.price' => ['required', 'integer', 'between:0,1000000000000'],
        ]);
        /** @var list<array{variant_id: int, price: int}> $entries */
        $entries = $data['entries'];
        $this->products->replacePriceListEntries($id, self::int($data['version']), $entries, AdminContext::fromRequest($request, 'Admin mengganti harga price list'));

        return response()->json(['data' => $this->products->priceListEntries($id)]);
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : throw ValidationException::withMessages(['version' => 'Version wajib.']);
    }
}
