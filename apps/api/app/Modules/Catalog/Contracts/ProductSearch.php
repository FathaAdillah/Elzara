<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface ProductSearch
{
    /** @return array<int, float> Product ids mapped to relevance scores. */
    public function search(string $term): array;
}
