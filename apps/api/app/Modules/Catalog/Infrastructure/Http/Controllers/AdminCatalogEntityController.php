<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Http\Controllers;

use App\Modules\Catalog\Application\AdminContext;
use App\Modules\Catalog\Application\CatalogEntityService;
use App\Modules\Catalog\Application\CatalogInput;
use App\Modules\Catalog\Application\CatalogResources;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Kategori, brand, koleksi, dan price list; nama resource datang dari default rute. */
final class AdminCatalogEntityController
{
    public function __construct(private readonly CatalogEntityService $entities) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->entities->list($request, self::resource($request)))->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->entities->find(self::resource($request), $id)])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $resource = self::resource($request);
        $data = CatalogInput::validate($request->all(), CatalogResources::rules($resource));

        return response()->json(['data' => $this->entities->create($resource, $data, AdminContext::fromRequest($request, 'Admin membuat '.$resource))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $resource = self::resource($request);
        $data = CatalogInput::validate($request->all(), CatalogResources::rules($resource, $id));
        $version = is_int($data['version'] ?? null) ? $data['version'] : 0;

        return response()->json(['data' => $this->entities->update($resource, $id, $version, $data, AdminContext::fromRequest($request, 'Admin mengubah '.$resource))]);
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->entities->delete(self::resource($request), $id, self::version($request), AdminContext::fromRequest($request, 'Admin menghapus '.self::resource($request)));

        return response()->noContent();
    }

    public static function version(Request $request): int
    {
        $version = filter_var($request->query('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($version === false) {
            throw ValidationException::withMessages(['version' => 'Query version wajib bilangan bulat >= 1.']);
        }

        return $version;
    }

    private static function resource(Request $request): string
    {
        $resource = $request->route()?->defaults['resource'] ?? null;

        return is_string($resource) ? $resource : 'categories';
    }
}
