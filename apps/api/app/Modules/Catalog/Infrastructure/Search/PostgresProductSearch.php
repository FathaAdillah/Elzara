<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Search;

use App\Modules\Catalog\Contracts\ProductSearch;
use Illuminate\Support\Facades\DB;

final class PostgresProductSearch implements ProductSearch
{
    public function search(string $term): array
    {
        $score = 'GREATEST(word_similarity(?, p.name), word_similarity(?, COALESCE(b.name, \'\')), word_similarity(?, COALESCE(p.series, \'\')))';
        $bindings = [$term, $term, $term];
        $rows = DB::table('products as p')->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')
            ->where('p.status', 'active')->whereNull('p.deleted_at')
            ->select('p.id')->selectRaw($score.' AS relevance', $bindings)->whereRaw($score.' >= 0.3', $bindings)->get();
        $matches = [];
        foreach ($rows as $row) {
            if (is_int($row->id) && is_numeric($row->relevance)) {
                $matches[$row->id] = (float) $row->relevance;
            }
        }

        return $matches;
    }
}
