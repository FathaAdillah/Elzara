<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Platform\Domain\Scalar;
use App\Platform\Outbox\DomainEvent;
use App\Platform\Outbox\OutboxWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Menulis `ProductUpdated` (Doc 12 §7) di transaksi yang sama dengan perubahan katalog. */
final class ProductEvents
{
    public function __construct(private readonly OutboxWriter $outbox) {}

    /** @param list<string> $changedFields */
    public function updated(int $productId, array $changedFields, int $actorId): void
    {
        $variantIds = DB::table('product_variants')->where('product_id', $productId)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => Scalar::int($id))->all();
        $this->outbox->record(new DomainEvent('ProductUpdated', 'product', $productId, [
            'product_id' => $productId,
            'variant_ids' => $variantIds,
            'changed_fields' => array_values(array_unique($changedFields)),
            'updated_by' => $actorId,
            'occurred_at' => Carbon::now()->format('Y-m-d\TH:i:s.uP'),
        ]));
    }

    /**
     * @param  iterable<int>  $productIds
     * @param  list<string>  $changedFields
     */
    public function updatedMany(iterable $productIds, array $changedFields, int $actorId): void
    {
        foreach ($productIds as $productId) {
            $this->updated($productId, $changedFields, $actorId);
        }
    }
}
