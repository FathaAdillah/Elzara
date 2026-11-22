<?php

declare(strict_types=1);

namespace App\Modules\Search\Infrastructure\Console;

use App\Modules\Search\Application\ProductIndexer;
use App\Modules\Search\Application\ReindexProductSearch;
use Illuminate\Console\Command;

final class ReindexProductsCommand extends Command
{
    protected $signature = 'search:products:reindex {--queue : Kirim ke antrean imports alih-alih menjalankan langsung}';

    protected $description = 'Bangun ulang indeks produk Elasticsearch dari PostgreSQL lalu swap alias';

    public function handle(ProductIndexer $indexer): int
    {
        if ($this->option('queue') === true) {
            ReindexProductSearch::dispatch();
            $this->line('queued queue=imports');

            return self::SUCCESS;
        }
        $result = (new ReindexProductSearch)->handle($indexer);
        $this->line("index={$result['index']} documents={$result['documents']}");

        return self::SUCCESS;
    }
}
