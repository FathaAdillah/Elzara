<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\SellableVariant;
use App\Modules\Catalog\Contracts\SellableVariants;
use Illuminate\Support\Facades\DB;

final class DatabaseSellableVariants implements SellableVariants
{
    public function find(int $variantId): ?SellableVariant
    {
        $row = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('v.id', $variantId)->where('v.is_active', true)->where('v.currency_code', 'IDR')->whereNull('v.deleted_at')->where('p.status', 'active')->whereNull('p.deleted_at')
            ->select('v.id', 'v.label', 'v.sku', 'p.name', 'p.unit_weight_g', 'p.fulfillment_modes')
            ->selectRaw("COALESCE((SELECT e.price FROM price_list_entries e JOIN price_lists l ON l.id=e.price_list_id WHERE e.variant_id=v.id AND l.is_active AND l.kind='retail' AND l.currency_code=v.currency_code AND e.currency_code=v.currency_code AND (l.valid_from IS NULL OR l.valid_from<=CURRENT_TIMESTAMP) AND (l.valid_to IS NULL OR l.valid_to>CURRENT_TIMESTAMP) ORDER BY l.valid_from DESC NULLS LAST,l.id DESC LIMIT 1),v.base_price) AS price")
            ->selectRaw('COALESCE((SELECT SUM(GREATEST(s.available_to_sell,0)) FROM stock_items s WHERE s.variant_id=v.id),0) AS available')->first();
        if ($row === null || ! is_string($row->name)) {
            return null;
        }
        $modes = explode(',', trim(is_string($row->fulfillment_modes) ? $row->fulfillment_modes : '', '{}'));
        $mode = in_array('rts', $modes, true) ? 'rts' : (in_array('po', $modes, true) ? 'pre_order' : (in_array('request', $modes, true) ? 'request_order' : 'rts'));
        $price = filter_var($row->price, FILTER_VALIDATE_INT);
        $available = filter_var($row->available, FILTER_VALIDATE_INT);
        $weight = filter_var($row->unit_weight_g, FILTER_VALIDATE_INT);
        if ($price === false || $available === false) {
            return null;
        }

        return new SellableVariant($variantId, $row->name, $price, $available, $mode, null, $weight === false ? null : $weight, (string) $row->label, (string) $row->sku);
    }
}
