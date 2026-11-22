<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Domain\CatalogRecordMissing;
use App\Platform\Domain\Exception\ResourceInUse;
use App\Platform\Domain\Exception\VersionStale;
use App\Platform\Domain\Scalar;
use App\Platform\Http\CursorPagination;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/** CRUD seragam + optimistic version (K6) + audit untuk kategori, brand, koleksi, dan price list. */
final class CatalogEntityService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly ProductEvents $events) {}

    /** @return array{data: list<stdClass>, meta: array{limit: int, has_more: bool, next_cursor: ?string, prev_cursor: ?string}} */
    public function list(Request $request, string $resource): array
    {
        $query = DB::table(CatalogResources::table($resource));
        $filters = [];
        $active = $request->query('is_active');
        if (is_string($active) && in_array($active, ['true', 'false'], true)) {
            $query->where('is_active', $active === 'true');
            $filters['is_active'] = $active;
        }

        return CursorPagination::paginate($request, $query, ['id' => 'desc'], $filters);
    }

    public function find(string $resource, int $id): stdClass
    {
        $row = DB::table(CatalogResources::table($resource))->where('id', $id)->first();

        return $row instanceof stdClass ? $row : throw new CatalogRecordMissing('Data katalog tidak ditemukan.');
    }

    /** @param array<string, mixed> $data */
    public function create(string $resource, array $data, AdminContext $context): stdClass
    {
        return DB::transaction(function () use ($resource, $data, $context): stdClass {
            $values = $this->only($resource, $data);
            $id = (int) DB::table(CatalogResources::table($resource))->insertGetId($values);
            $row = $this->find($resource, $id);
            $this->audit->record($context->actorId, 'catalog.create', CatalogResources::table($resource), $id, [], Scalar::fields($row),
                $context->reason, $context->requestId, $context->ip, $context->userAgent);

            return $row;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(string $resource, int $id, int $version, array $data, AdminContext $context): stdClass
    {
        return DB::transaction(function () use ($resource, $id, $version, $data, $context): stdClass {
            $before = $this->lock($resource, $id, $version);
            $values = $this->only($resource, $data);
            DB::table(CatalogResources::table($resource))->where('id', $id)
                ->update([...$values, 'version' => $version + 1, 'updated_at' => Carbon::now()]);
            $row = $this->find($resource, $id);
            $this->audit->record($context->actorId, 'catalog.update', CatalogResources::table($resource), $id, Scalar::fields($before), Scalar::fields($row),
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $this->events->updatedMany($this->affectedProducts($resource, $id), array_map(
                static fn (string $field): string => CatalogResources::table($resource).'.'.$field, array_keys($values)), $context->actorId);

            return $row;
        });
    }

    public function delete(string $resource, int $id, int $version, AdminContext $context): void
    {
        try {
            DB::transaction(function () use ($resource, $id, $version, $context): void {
                $before = $this->lock($resource, $id, $version);
                $affected = $this->affectedProducts($resource, $id);
                DB::table(CatalogResources::table($resource))->where('id', $id)->delete();
                $this->audit->record($context->actorId, 'catalog.delete', CatalogResources::table($resource), $id, Scalar::fields($before), [],
                    $context->reason, $context->requestId, $context->ip, $context->userAgent);
                $this->events->updatedMany($affected, [CatalogResources::table($resource)], $context->actorId);
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                throw new ResourceInUse('Data masih dirujuk produk atau data lain; nonaktifkan alih-alih menghapus.', previous: $exception);
            }

            throw $exception;
        }
    }

    private function lock(string $resource, int $id, int $version): stdClass
    {
        $row = DB::table(CatalogResources::table($resource))->where('id', $id)->lockForUpdate()->first();
        if (! $row instanceof stdClass) {
            throw new CatalogRecordMissing('Data katalog tidak ditemukan.');
        }
        if (Scalar::int($row->version) !== $version) {
            throw new VersionStale('Versi terkini '.Scalar::string($row->version).", permintaan membawa {$version}.");
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(string $resource, array $data): array
    {
        return array_intersect_key($data, array_flip(CatalogResources::columns($resource)));
    }

    /** @return list<int> produk yang dokumen indeksnya ikut berubah */
    private function affectedProducts(string $resource, int $id): array
    {
        $query = match ($resource) {
            'categories' => DB::table('products')->where('category_id', $id)->whereNull('deleted_at')->select('id'),
            'brands' => DB::table('products')->where('brand_id', $id)->whereNull('deleted_at')->select('id'),
            'collections' => DB::table('product_collections')->where('collection_id', $id)->select('product_id as id'),
            default => null,
        };

        return $query === null ? [] : array_values($query->orderBy('id')->pluck('id')->map(static fn (mixed $value): int => Scalar::int($value))->all());
    }
}
