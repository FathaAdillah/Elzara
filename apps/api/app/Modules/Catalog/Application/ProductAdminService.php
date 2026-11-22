<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Modules\Catalog\Domain\CatalogRecordMissing;
use App\Platform\Domain\Exception\VersionStale;
use App\Platform\Domain\Scalar;
use App\Platform\Http\CursorPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Produk, varian, relasi koleksi, dan entri price list; setiap tulis menaikkan `version` dan menulis ProductUpdated. */
final class ProductAdminService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly ProductEvents $events) {}

    /** @return array{data: list<stdClass>, meta: array{limit: int, has_more: bool, next_cursor: ?string, prev_cursor: ?string}} */
    public function list(Request $request, CategoryScope $scope): array
    {
        $query = $scope->constrain(DB::table('products'), 'category_id')->whereNull('deleted_at')
            ->select(['id', 'slug', 'name', 'brand_id', 'category_id', 'status', 'origin_country', 'version', 'created_at', 'updated_at']);
        $filters = [];
        foreach (['status' => 'string', 'category_id' => 'int', 'brand_id' => 'int'] as $field => $type) {
            $value = $request->query($field);
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($type === 'int' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw ValidationException::withMessages([$field => 'Harus bilangan bulat.']);
            }
            $query->where($field, $type === 'int' ? (int) $value : $value);
            $filters[$field] = $value;
        }

        $page = CursorPagination::paginate($request, $query, ['id' => 'desc'], $filters);
        foreach ($page['data'] as $row) {
            $row->id = Scalar::int($row->id);
            $row->version = Scalar::int($row->version);
        }

        return $page;
    }

    /** @return array<string, mixed> */
    public function show(int $id, ?CategoryScope $scope = null): array
    {
        $product = DB::table('products')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $product instanceof stdClass || ($scope !== null && ! $scope->covers($product->category_id))) {
            throw new CatalogRecordMissing('Produk tidak ditemukan.');
        }
        $data = Scalar::fields($product);
        unset($data['deleted_at']);
        $data['fulfillment_modes'] = self::pgArray($product->fulfillment_modes);
        $data['collection_ids'] = DB::table('product_collections')->where('product_id', $id)->orderBy('collection_id')->pluck('collection_id')
            ->map(static fn (mixed $value): int => Scalar::int($value))->all();
        $data['variants'] = DB::table('product_variants')->where('product_id', $id)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'product_id', 'sku', 'label', 'base_price', 'currency_code', 'track_stock', 'is_active', 'version'])->all();

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, AdminContext $context, CategoryScope $scope): int
    {
        $scope->assertCovers($data['category_id'] ?? null);

        return DB::transaction(function () use ($data, $context): int {
            $id = (int) DB::table('products')->insertGetId(self::columns($data, ProductRules::PRODUCT_COLUMNS));
            $this->writeArrays($id, $data);
            $variants = $data['variants'] ?? [];
            foreach (is_array($variants) ? $variants : [] as $variant) {
                DB::table('product_variants')->insert(['product_id' => $id, ...self::columns(is_array($variant) ? Scalar::fields((object) $variant) : [], ProductRules::VARIANT_COLUMNS)]);
            }
            $this->audit->record($context->actorId, 'catalog.create', 'products', $id, [], $this->show($id),
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $this->events->updated($id, ['created'], $context->actorId);

            return $id;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, int $version, array $data, AdminContext $context, CategoryScope $scope): void
    {
        DB::transaction(function () use ($id, $version, $data, $context, $scope): void {
            $scope->assertCovers($this->lock('products', $id, $version, true)->category_id);
            if (array_key_exists('category_id', $data)) {
                $scope->assertCovers($data['category_id']);
            }
            $before = $this->show($id);
            $values = self::columns($data, ProductRules::PRODUCT_COLUMNS);
            DB::table('products')->where('id', $id)->update([...$values, 'version' => $version + 1, 'updated_at' => Carbon::now()]);
            $this->writeArrays($id, $data);
            $after = $this->show($id);
            $this->audit->record($context->actorId, 'catalog.update', 'products', $id, $before, $after,
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $changed = array_keys(array_intersect_key($data, array_flip([...ProductRules::PRODUCT_COLUMNS, 'fulfillment_modes', 'collection_ids'])));
            $this->events->updated($id, $changed, $context->actorId);
        });
    }

    public function delete(int $id, int $version, AdminContext $context, CategoryScope $scope): void
    {
        DB::transaction(function () use ($id, $version, $context, $scope): void {
            $scope->assertCovers($this->lock('products', $id, $version, true)->category_id);
            $before = $this->show($id);
            DB::table('products')->where('id', $id)->update(['deleted_at' => Carbon::now(), 'status' => 'archived', 'version' => $version + 1, 'updated_at' => Carbon::now()]);
            $this->audit->record($context->actorId, 'catalog.delete', 'products', $id, $before, [],
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $this->events->updated($id, ['deleted_at'], $context->actorId);
        });
    }

    /** @param array<string, mixed> $data */
    public function createVariant(int $productId, array $data, AdminContext $context, CategoryScope $scope): stdClass
    {
        return DB::transaction(function () use ($productId, $data, $context, $scope): stdClass {
            $product = DB::table('products')->where('id', $productId)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $product instanceof stdClass) {
                throw new CatalogRecordMissing('Produk tidak ditemukan.');
            }
            $scope->assertCovers($product->category_id);
            $id = (int) DB::table('product_variants')->insertGetId(['product_id' => $productId, ...self::columns($data, ProductRules::VARIANT_COLUMNS)]);
            $variant = $this->variant($id);
            $this->audit->record($context->actorId, 'catalog.create', 'product_variants', $id, [], Scalar::fields($variant),
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $this->events->updated($productId, ['variants'], $context->actorId);

            return $variant;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateVariant(int $id, int $version, array $data, AdminContext $context, CategoryScope $scope): stdClass
    {
        return DB::transaction(function () use ($id, $version, $data, $context, $scope): stdClass {
            $before = $this->lockVariant($id, $version, $scope);
            $values = self::columns($data, ProductRules::VARIANT_COLUMNS);
            DB::table('product_variants')->where('id', $id)->update([...$values, 'version' => $version + 1, 'updated_at' => Carbon::now()]);
            $variant = $this->variant($id);
            $this->audit->record($context->actorId, 'catalog.update', 'product_variants', $id, Scalar::fields($before), Scalar::fields($variant),
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $this->events->updated(Scalar::int($variant->product_id), array_map(static fn (string $field): string => 'variants.'.$field, array_keys($values)), $context->actorId);

            return $variant;
        });
    }

    public function deleteVariant(int $id, int $version, AdminContext $context, CategoryScope $scope): void
    {
        DB::transaction(function () use ($id, $version, $context, $scope): void {
            $before = $this->lockVariant($id, $version, $scope);
            DB::table('product_variants')->where('id', $id)->update(['deleted_at' => Carbon::now(), 'is_active' => false, 'version' => $version + 1, 'updated_at' => Carbon::now()]);
            $this->audit->record($context->actorId, 'catalog.delete', 'product_variants', $id, Scalar::fields($before), [],
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
            $this->events->updated(Scalar::int($before->product_id), ['variants'], $context->actorId);
        });
    }

    /** Locks the parent product before the variant, in the same order as the CSV import, then checks its category. */
    private function lockVariant(int $id, int $version, CategoryScope $scope): stdClass
    {
        $productId = DB::table('product_variants')->where('id', $id)->value('product_id');
        $categoryId = DB::table('products')->where('id', $productId)->lockForUpdate()->value('category_id');
        $before = $this->lock('product_variants', $id, $version, true);
        $scope->assertCovers($categoryId);

        return $before;
    }

    /** @return list<stdClass> */
    public function priceListEntries(int $priceListId, CategoryScope $scope): array
    {
        if (! DB::table('price_lists')->where('id', $priceListId)->exists()) {
            throw new CatalogRecordMissing('Price list tidak ditemukan.');
        }

        $query = DB::table('price_list_entries as e')->where('e.price_list_id', $priceListId)->orderBy('e.variant_id');
        if (! $scope->isGlobal()) {
            $scope->constrain($query->join('product_variants as v', 'v.id', '=', 'e.variant_id')->join('products as p', 'p.id', '=', 'v.product_id'), 'p.category_id');
        }
        /** @var list<stdClass> $entries */
        $entries = $query->get(['e.variant_id', 'e.price', 'e.currency_code'])->values()->all();

        return $entries;
    }

    /** @param list<array{variant_id: int, price: int}> $entries */
    public function replacePriceListEntries(int $priceListId, int $version, array $entries, AdminContext $context, CategoryScope $scope): void
    {
        $scope->assertGlobal();
        DB::transaction(function () use ($priceListId, $version, $entries, $context): void {
            $list = $this->lock('price_lists', $priceListId, $version, false);
            $before = $this->priceListEntries($priceListId, CategoryScope::global());
            DB::table('price_list_entries')->where('price_list_id', $priceListId)->delete();
            foreach ($entries as $entry) {
                DB::table('price_list_entries')->insert(['price_list_id' => $priceListId, 'variant_id' => $entry['variant_id'], 'price' => $entry['price'], 'currency_code' => $list->currency_code]);
            }
            DB::table('price_lists')->where('id', $priceListId)->update(['version' => $version + 1, 'updated_at' => Carbon::now()]);
            $this->audit->record($context->actorId, 'catalog.update', 'price_lists', $priceListId, ['entries' => $before], ['entries' => $this->priceListEntries($priceListId, CategoryScope::global())],
                $context->reason, $context->requestId, $context->ip, $context->userAgent);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    public static function columns(array $data, array $columns): array
    {
        return array_intersect_key($data, array_flip($columns));
    }

    /** @return list<string> */
    public static function pgArray(mixed $value): array
    {
        if (! is_string($value) || $value === '{}' || $value === '') {
            return [];
        }

        return array_values(array_filter(str_getcsv(trim($value, '{}'), ',', '"', ''), static fn (?string $item): bool => $item !== null && $item !== ''));
    }

    /** @param list<string> $values */
    public static function toPgArray(array $values): string
    {
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.addcslashes($value, '"\\').'"', $values)).'}';
    }

    /** @param array<string, mixed> $data */
    private function writeArrays(int $productId, array $data): void
    {
        if (isset($data['fulfillment_modes']) && is_array($data['fulfillment_modes'])) {
            $modes = array_values(array_filter($data['fulfillment_modes'], is_string(...)));
            DB::update('UPDATE products SET fulfillment_modes = ?::varchar(32)[] WHERE id = ?', [self::toPgArray($modes), $productId]);
        }
        if (isset($data['collection_ids']) && is_array($data['collection_ids'])) {
            DB::table('product_collections')->where('product_id', $productId)->delete();
            foreach ($data['collection_ids'] as $collectionId) {
                DB::table('product_collections')->insert(['product_id' => $productId, 'collection_id' => $collectionId]);
            }
        }
    }

    private function variant(int $id): stdClass
    {
        $variant = DB::table('product_variants')->where('id', $id)
            ->first(['id', 'product_id', 'sku', 'label', 'base_price', 'currency_code', 'track_stock', 'is_active', 'version', 'created_at', 'updated_at']);

        return $variant instanceof stdClass ? $variant : throw new CatalogRecordMissing('Varian tidak ditemukan.');
    }

    private function lock(string $table, int $id, int $version, bool $softDeletes): stdClass
    {
        $query = DB::table($table)->where('id', $id)->lockForUpdate();
        if ($softDeletes) {
            $query->whereNull('deleted_at');
        }
        $row = $query->first();
        if (! $row instanceof stdClass) {
            throw new CatalogRecordMissing('Data katalog tidak ditemukan.');
        }
        if (Scalar::int($row->version) !== $version) {
            throw new VersionStale('Versi terkini '.Scalar::string($row->version).", permintaan membawa {$version}.");
        }

        return $row;
    }
}
