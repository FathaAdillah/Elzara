<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

interface CategoryScopes
{
    /** @return list<int>|null kategori (beserta turunannya) yang boleh disentuh operator untuk `$ability`; null berarti global */
    public function allowedCategoryIds(int $actorId, string $ability): ?array;
}
