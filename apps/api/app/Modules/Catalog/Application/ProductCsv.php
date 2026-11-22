<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Audit\Contracts\AuditRecorder;
use App\Platform\Domain\Scalar;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Import/export CSV katalog: satu baris per varian (atau satu baris produk tanpa varian).
 * Import bersifat semua-atau-tidak: satu baris salah membatalkan seluruh berkas.
 */
final class ProductCsv
{
    public const COLUMNS = ['product_slug', 'product_name', 'category_slug', 'brand_slug', 'series', 'status', 'origin_country', 'fulfillment_modes',
        'description', 'sku', 'variant_label', 'base_price', 'currency_code', 'track_stock', 'is_active'];

    public const MAX_ROWS = 5000;

    private const PRODUCT_FIELDS = ['product_name', 'category_slug', 'brand_slug', 'series', 'status', 'origin_country', 'fulfillment_modes', 'description'];

    public function __construct(private readonly AuditRecorder $audit, private readonly ProductEvents $events) {}

    /**
     * @param  resource  $handle
     * @return array{errors: list<array{row: int, column: string, code: string, detail: string}>, summary: array{rows: int, products_created: int, products_updated: int, variants_created: int, variants_updated: int}}
     */
    public function import($handle, bool $dryRun, AdminContext $context): array
    {
        [$rows, $errors] = $this->parse($handle);
        $summary = ['rows' => count($rows), 'products_created' => 0, 'products_updated' => 0, 'variants_created' => 0, 'variants_updated' => 0];
        if ($errors !== []) {
            return ['errors' => $errors, 'summary' => $summary];
        }
        $products = [];
        foreach ($rows as $line => $row) {
            $products[$row['product_slug']]['row'] ??= $row;
            $products[$row['product_slug']]['lines'][] = $line;
            if ($row['sku'] !== '') {
                $products[$row['product_slug']]['variants'][] = $row;
            }
        }
        $existingProducts = DB::table('products')->whereIn('slug', array_keys($products))->pluck('id', 'slug');
        $skus = array_values(array_filter(array_column($rows, 'sku'), static fn (string $sku): bool => $sku !== ''));
        $existingVariants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->whereIn('v.sku', $skus)
            ->get(['v.sku', 'v.id', 'p.slug'])->keyBy('sku');
        foreach ($rows as $line => $row) {
            $owner = $existingVariants->get($row['sku']);
            if ($owner instanceof stdClass && $owner->slug !== $row['product_slug']) {
                $errors[] = ['row' => $line, 'column' => 'sku', 'code' => 'sku_taken', 'detail' => 'SKU sudah dipakai produk '.Scalar::string($owner->slug).'.'];
            }
        }
        foreach ($products as $slug => $product) {
            $summary[$existingProducts->has($slug) ? 'products_updated' : 'products_created']++;
            foreach ($product['variants'] ?? [] as $variant) {
                $summary[$existingVariants->has($variant['sku']) ? 'variants_updated' : 'variants_created']++;
            }
        }
        if ($errors !== [] || $dryRun) {
            return ['errors' => $errors, 'summary' => $summary];
        }

        DB::transaction(function () use ($products, $context): void {
            $categories = DB::table('categories')->pluck('id', 'slug');
            $brands = DB::table('brands')->pluck('id', 'slug');
            foreach ($products as $slug => $product) {
                $row = $product['row'];
                $values = [
                    'name' => $row['product_name'], 'category_id' => $categories[$row['category_slug']],
                    'brand_id' => $row['brand_slug'] === '' ? null : $brands[$row['brand_slug']], 'series' => self::nullable($row['series']),
                    'status' => $row['status'] === '' ? 'draft' : $row['status'], 'origin_country' => $row['origin_country'],
                    'description' => self::nullable($row['description']),
                ];
                $existing = DB::table('products')->where('slug', $slug)->lockForUpdate()->first();
                if ($existing instanceof stdClass) {
                    $id = Scalar::int($existing->id);
                    DB::table('products')->where('id', $id)->update([...$values, 'deleted_at' => null, 'version' => Scalar::int($existing->version) + 1, 'updated_at' => Carbon::now()]);
                } else {
                    $id = (int) DB::table('products')->insertGetId(['slug' => $slug, ...$values]);
                }
                $modes = $row['fulfillment_modes'] === '' ? [] : explode('|', $row['fulfillment_modes']);
                DB::update('UPDATE products SET fulfillment_modes = ?::varchar(32)[] WHERE id = ?', [ProductAdminService::toPgArray($modes), $id]);
                foreach ($product['variants'] ?? [] as $variant) {
                    $variantValues = [
                        'product_id' => $id, 'label' => $variant['variant_label'], 'base_price' => (int) $variant['base_price'],
                        'currency_code' => $variant['currency_code'] === '' ? 'IDR' : $variant['currency_code'],
                        'track_stock' => self::bool($variant['track_stock'], true), 'is_active' => self::bool($variant['is_active'], true),
                    ];
                    $current = DB::table('product_variants')->where('sku', $variant['sku'])->lockForUpdate()->first();
                    if ($current instanceof stdClass && Scalar::int($current->product_id) !== $id) {
                        throw ValidationException::withMessages(['file' => "SKU {$variant['sku']} kini dipakai produk lain; ulangi impor."]);
                    }
                    if ($current instanceof stdClass) {
                        DB::table('product_variants')->where('id', $current->id)
                            ->update([...$variantValues, 'deleted_at' => null, 'version' => Scalar::int($current->version) + 1, 'updated_at' => Carbon::now()]);
                    } else {
                        DB::table('product_variants')->insert(['sku' => $variant['sku'], ...$variantValues]);
                    }
                }
                $this->audit->record($context->actorId, 'catalog.import', 'products', $id, $existing instanceof stdClass ? Scalar::fields($existing) : [],
                    ['slug' => $slug, 'lines' => $product['lines']], $context->reason, $context->requestId, $context->ip, $context->userAgent);
                $this->events->updated($id, ['import'], $context->actorId);
            }
        });

        return ['errors' => [], 'summary' => $summary];
    }

    /** @param resource $output */
    public function export($output): void
    {
        fputcsv($output, self::COLUMNS, ',', '"', '');
        DB::table('products as p')->join('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('product_variants as v', static function (JoinClause $join): void {
                $join->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at');
            })
            ->whereNull('p.deleted_at')
            ->select(['p.slug', 'p.name', 'c.slug as category_slug', 'b.slug as brand_slug', 'p.series', 'p.status', 'p.origin_country', 'p.fulfillment_modes',
                'p.description', 'v.sku', 'v.label', 'v.base_price', 'v.currency_code', 'v.track_stock', 'v.is_active', 'p.id', DB::raw('COALESCE(v.id, 0) AS variant_order')])
            ->orderBy('p.id')->orderBy('variant_order')
            ->each(static function (stdClass $row) use ($output): void {
                $cells = [$row->slug, $row->name, $row->category_slug, $row->brand_slug, $row->series, $row->status, $row->origin_country,
                    implode('|', ProductAdminService::pgArray($row->fulfillment_modes)), $row->description, $row->sku, $row->label, $row->base_price,
                    $row->currency_code, $row->sku === null ? null : ($row->track_stock ? 'true' : 'false'), $row->sku === null ? null : ($row->is_active ? 'true' : 'false')];
                fputcsv($output, array_map(self::cell(...), $cells), ',', '"', '');
            }, 500);
    }

    /**
     * @param  resource  $handle
     * @return array{0: array<int, array<string, string>>, 1: list<array{row: int, column: string, code: string, detail: string}>}
     */
    private function parse($handle): array
    {
        $header = fgetcsv($handle, null, ',', '"', '');
        if (is_array($header) && isset($header[0])) {
            $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
        }
        if (! is_array($header) || array_map(static fn (?string $cell): string => trim((string) $cell), $header) !== self::COLUMNS) {
            return [[], [['row' => 1, 'column' => '*', 'code' => 'invalid_header', 'detail' => 'Header harus persis: '.implode(',', self::COLUMNS)]]];
        }
        $rows = [];
        $errors = [];
        $line = 1;
        $categories = DB::table('categories')->pluck('id', 'slug');
        $brands = DB::table('brands')->pluck('id', 'slug');
        $seenSkus = [];
        $productRows = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null]) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = ['row' => $line, 'column' => '*', 'code' => 'too_many_rows', 'detail' => 'Maksimal '.self::MAX_ROWS.' baris per berkas.'];
                break;
            }
            if (count($cells) !== count(self::COLUMNS)) {
                $errors[] = ['row' => $line, 'column' => '*', 'code' => 'column_count', 'detail' => 'Jumlah kolom harus '.count(self::COLUMNS).'.'];

                continue;
            }
            $row = array_combine(self::COLUMNS, array_map(static fn (?string $cell): string => trim((string) $cell), $cells));
            foreach ($this->validateRow($row) as $column => $detail) {
                $errors[] = ['row' => $line, 'column' => $column, 'code' => 'invalid', 'detail' => $detail];
            }
            if ($row['category_slug'] !== '' && ! $categories->has($row['category_slug'])) {
                $errors[] = ['row' => $line, 'column' => 'category_slug', 'code' => 'unknown_category', 'detail' => 'Kategori tidak ditemukan.'];
            }
            if ($row['brand_slug'] !== '' && ! $brands->has($row['brand_slug'])) {
                $errors[] = ['row' => $line, 'column' => 'brand_slug', 'code' => 'unknown_brand', 'detail' => 'Brand tidak ditemukan.'];
            }
            if ($row['sku'] !== '') {
                $key = strtolower($row['sku']);
                if (isset($seenSkus[$key])) {
                    $errors[] = ['row' => $line, 'column' => 'sku', 'code' => 'duplicate_sku', 'detail' => "SKU sudah muncul di baris {$seenSkus[$key]}."];
                }
                $seenSkus[$key] ??= $line;
            }
            $productFields = array_intersect_key($row, array_flip(self::PRODUCT_FIELDS));
            if (isset($productRows[$row['product_slug']]) && $productRows[$row['product_slug']]['fields'] !== $productFields) {
                $errors[] = ['row' => $line, 'column' => 'product_slug', 'code' => 'inconsistent_product',
                    'detail' => "Kolom produk berbeda dengan baris {$productRows[$row['product_slug']]['line']} untuk slug yang sama."];
            }
            $productRows[$row['product_slug']] ??= ['fields' => $productFields, 'line' => $line];
            $rows[$line] = $row;
        }
        if ($rows === [] && $errors === []) {
            $errors[] = ['row' => 2, 'column' => '*', 'code' => 'empty', 'detail' => 'Berkas tidak memuat baris data.'];
        }

        return [$rows, $errors];
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>
     */
    private function validateRow(array $row): array
    {
        $variant = $row['sku'] !== '';
        $validator = Validator::make($row, [
            'product_slug' => ['required', 'max:120', 'regex:'.CatalogResources::SLUG],
            'product_name' => ['required', 'max:200'],
            'category_slug' => ['required'],
            'series' => ['max:120'],
            'status' => ['nullable', Rule::in(ProductRules::STATUSES)],
            'origin_country' => ['required', 'regex:/\A[A-Z]{2}\z/'],
            'fulfillment_modes' => ['nullable', 'regex:/\A(?:rts|po|request)(?:\|(?:rts|po|request))*\z/'],
            'description' => ['max:20000'],
            'sku' => ['nullable', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/'],
            'variant_label' => [$variant ? 'required' : 'prohibited', 'max:160'],
            'base_price' => [$variant ? 'required' : 'prohibited', 'regex:/\A\d{1,13}\z/'],
            'currency_code' => [$variant ? 'nullable' : 'prohibited', 'regex:/\A[A-Z]{3}\z/'],
            'track_stock' => [$variant ? 'nullable' : 'prohibited', Rule::in(['true', 'false', '1', '0'])],
            'is_active' => [$variant ? 'nullable' : 'prohibited', Rule::in(['true', 'false', '1', '0'])],
        ]);
        $errors = [];
        foreach ($validator->errors()->messages() as $column => $messages) {
            $errors[(string) $column] = implode(' ', $messages);
        }

        return $errors;
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function bool(string $value, bool $default): bool
    {
        return $value === '' ? $default : in_array($value, ['true', '1'], true);
    }

    /** Netralkan formula spreadsheet (CSV injection) saat ekspor. */
    private static function cell(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }
}
