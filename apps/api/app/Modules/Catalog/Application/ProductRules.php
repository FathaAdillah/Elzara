<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Validation\Rule;

/** Aturan validasi produk + varian, dipakai bersama oleh API admin dan import CSV. */
final class ProductRules
{
    public const FULFILLMENT_MODES = ['rts', 'po', 'request'];

    public const STATUSES = ['draft', 'active', 'archived'];

    public const PRODUCT_COLUMNS = ['slug', 'name', 'brand_id', 'series', 'category_id', 'status', 'origin_country', 'unit_weight_g', 'hs_code',
        'description', 'care_notes', 'return_policy_notes', 'meta_title', 'meta_description'];

    public const VARIANT_COLUMNS = ['sku', 'label', 'base_price', 'currency_code', 'track_stock', 'is_active'];

    /** @return array<string, mixed> */
    public static function product(?int $id = null): array
    {
        $required = $id === null ? 'required' : 'sometimes';
        $slug = Rule::unique('products', 'slug');

        return [
            'slug' => [$required, 'string', 'max:120', 'regex:'.CatalogResources::SLUG, $id === null ? $slug : $slug->ignore($id)],
            'name' => [$required, 'string', 'max:200'],
            'brand_id' => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')],
            'series' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => [$required, 'integer', Rule::exists('categories', 'id')],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'origin_country' => [$required, 'string', 'regex:/\A[A-Z]{2}\z/'],
            'unit_weight_g' => ['sometimes', 'nullable', 'integer', 'between:1,1000000'],
            'hs_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'care_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'return_policy_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:300'],
            'fulfillment_modes' => ['sometimes', 'array', 'max:3'],
            'fulfillment_modes.*' => ['string', 'distinct', Rule::in(self::FULFILLMENT_MODES)],
            'collection_ids' => ['sometimes', 'array', 'max:50'],
            'collection_ids.*' => ['integer', 'distinct', Rule::exists('collections', 'id')],
            'reason' => ['sometimes', 'string', 'min:5', 'max:320'],
            ...($id === null ? self::nestedVariants() : ['version' => ['required', 'integer', 'min:1']]),
        ];
    }

    /** @return array<string, mixed> */
    public static function variant(?int $id = null): array
    {
        $required = $id === null ? 'required' : 'sometimes';
        $sku = Rule::unique('product_variants', 'sku');

        return [
            'sku' => [$required, 'string', 'max:64', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $id === null ? $sku : $sku->ignore($id)],
            'label' => [$required, 'string', 'max:160'],
            'base_price' => [$required, 'integer', 'between:0,1000000000000'],
            'currency_code' => ['sometimes', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'track_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'string', 'min:5', 'max:320'],
            ...($id === null ? [] : ['version' => ['required', 'integer', 'min:1']]),
        ];
    }

    /** @return array<string, mixed> */
    private static function nestedVariants(): array
    {
        return [
            'variants' => ['sometimes', 'array', 'max:100'],
            'variants.*' => ['array:sku,label,base_price,currency_code,track_stock,is_active'],
            'variants.*.sku' => ['required', 'string', 'max:64', 'distinct', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', Rule::unique('product_variants', 'sku')],
            'variants.*.label' => ['required', 'string', 'max:160'],
            'variants.*.base_price' => ['required', 'integer', 'between:0,1000000000000'],
            'variants.*.currency_code' => ['sometimes', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'variants.*.track_stock' => ['sometimes', 'boolean'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}
