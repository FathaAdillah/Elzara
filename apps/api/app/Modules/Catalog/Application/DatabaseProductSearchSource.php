<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\ProductSearchSource;
use App\Platform\Domain\Scalar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final class DatabaseProductSearchSource implements ProductSearchSource
{
    public function documents(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        /** @var Collection<int, stdClass> $products */
        $products = DB::table('products as p')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('p.id', $productIds)->whereNull('p.deleted_at')
            ->get(['p.id', 'p.slug', 'p.name', 'p.series', 'p.description', 'p.status', 'p.origin_country', 'p.fulfillment_modes', 'p.version', 'p.updated_at', 'p.category_id',
                'b.slug as brand_slug', 'b.name as brand_name', 'c.slug as category_slug', 'c.name_key as category_name_key']);
        $variants = DB::table('product_variants')->whereIn('product_id', $productIds)->whereNull('deleted_at')->where('is_active', true)
            ->orderBy('id')->get(['product_id', 'sku', 'label', 'base_price', 'currency_code'])->groupBy('product_id');
        $collections = DB::table('product_collections as pc')->join('collections as co', 'co.id', '=', 'pc.collection_id')
            ->whereIn('pc.product_id', $productIds)->orderBy('co.slug')->get(['pc.product_id', 'co.slug'])->groupBy('product_id');

        $documents = [];
        foreach ($products as $product) {
            $id = Scalar::int($product->id);
            /** @var Collection<int, stdClass> $own */
            $own = $variants->get($id, collect());
            $currency = Scalar::string($own->first()->currency_code ?? 'IDR');
            $prices = $own->filter(static fn (stdClass $variant): bool => $variant->currency_code === $currency)
                ->map(static fn (stdClass $variant): int => Scalar::int($variant->base_price));
            $documents[$id] = [
                'id' => $id,
                'slug' => $product->slug,
                'name' => $product->name,
                'series' => $product->series,
                'description' => $product->description,
                'status' => $product->status,
                'origin_country' => $product->origin_country,
                'fulfillment_modes' => ProductAdminService::pgArray($product->fulfillment_modes),
                'brand' => $product->brand_slug,
                'brand_name' => $product->brand_name,
                'category_id' => Scalar::int($product->category_id),
                'category' => $product->category_slug,
                'category_name' => $product->category_name_key,
                'collections' => $collections->get($id, collect())->pluck('slug')->values()->all(),
                'skus' => $own->pluck('sku')->values()->all(),
                'variant_labels' => $own->pluck('label')->values()->all(),
                'price_min' => $prices->min(),
                'price_max' => $prices->max(),
                'currency_code' => $currency,
                'source_version' => Scalar::int($product->version),
                'updated_at' => Carbon::parse(Scalar::string($product->updated_at))->toIso8601ZuluString(),
            ];
        }

        return $documents;
    }

    public function eachProductIdChunk(int $size, callable $consumer): void
    {
        DB::table('products')->whereNull('deleted_at')->select('id')->orderBy('id')
            ->chunkById($size, static function (Collection $rows) use ($consumer): void {
                $consumer(array_values($rows->map(static fn (stdClass $row): int => Scalar::int($row->id))->all()));
            });
    }
}
