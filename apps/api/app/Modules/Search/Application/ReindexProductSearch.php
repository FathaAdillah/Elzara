<?php

declare(strict_types=1);

namespace App\Modules\Search\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/** Rebuild penuh indeks produk dari PostgreSQL ke indeks baru, lalu swap alias atomik. */
final class ReindexProductSearch implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onQueue('imports');
    }

    /** @return array{index: string, documents: int} */
    public function handle(ProductIndexer $indexer): array
    {
        $result = $indexer->reindexAll((int) Carbon::now()->format('Uu'));
        Log::info('search.product_index.reindexed', $result);

        return $result;
    }
}
