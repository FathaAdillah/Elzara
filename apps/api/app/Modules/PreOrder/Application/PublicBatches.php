<?php

declare(strict_types=1);

namespace App\Modules\PreOrder\Application;

use App\Modules\Catalog\Contracts\SellableVariants;
use App\Platform\Domain\PublicId;
use App\Platform\Http\CursorPagination;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;

final class PublicBatches
{
    public function __construct(private readonly SellableVariants $variants) {}

    /** @return array<string,mixed> */
    public function listing(Request $request): array
    {
        $page = CursorPagination::paginate($request, $this->open(), ['order_window_close_at' => 'asc', 'id' => 'asc'], []);

        return ['data' => array_map($this->resource(...), $page['data']), 'meta' => $page['meta'], 'now' => now()->toIso8601String()];
    }

    /** @return array<string,mixed> */
    public function detail(string $id): array
    {
        $batch = $this->open()->where('public_id', $id)->firstOrFail();
        $items = [];
        foreach (DB::table('preorder_batch_items')->where('batch_id', $batch->id)->where('is_active', true)->orderBy('id')->get() as $row) {
            $variant = $this->variants->find(BatchValue::integer($row->variant_id));
            if ($variant === null || $variant->mode !== 'pre_order') {
                continue;
            }
            $items[] = ['id' => $row->public_id, 'variant_id' => PublicId::encode('var', $variant->id), 'product_name' => $variant->name,
                'product_slug' => $variant->slug, 'variant_name' => $variant->label, 'price' => ['amount_minor' => BatchValue::integer($row->price), 'currency' => $row->currency_code],
                'quota' => $row->quota, 'remaining' => BatchValue::integer($row->quota) - BatchValue::integer($row->reserved_qty), 'max_per_account' => $row->max_per_account,
                'opens_at' => BatchValue::time($row->opens_at ?? $batch->order_window_open_at)->toIso8601String(),
                'closes_at' => BatchValue::time($row->closes_at ?? $batch->order_window_close_at)->toIso8601String(), 'status' => $row->status];
        }

        return $this->resource($batch) + ['items' => $items];
    }

    private function open(): Builder
    {
        return DB::table('preorder_batches')->where('status', 'open')->where('order_window_open_at', '<=', now())->where('order_window_close_at', '>', now());
    }

    /** @return array<string,mixed> */
    private function resource(stdClass $batch): array
    {
        return ['id' => $batch->public_id, 'name' => $batch->title, 'status' => $batch->status,
            'opens_at' => BatchValue::time($batch->order_window_open_at)->toIso8601String(), 'closes_at' => BatchValue::time($batch->order_window_close_at)->toIso8601String(),
            'now' => now()->toIso8601String(), 'eta_min_date' => $batch->eta_min_date, 'eta_max_date' => $batch->eta_max_date,
            'deposit_policy' => ['kind' => $batch->deposit_kind, 'value' => $batch->deposit_value], 'currency' => $batch->currency_code, 'shipping_mode' => $batch->shipping_mode];
    }
}
