<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/** Scope baris dari `role_scopes` (Doc 06 §2): permission menjawab aksi, scope menjawab baris mana. */
interface RowScopes
{
    /** @return list<int>|null id baris yang boleh disentuh untuk `$ability`; null berarti global */
    public function allowedIds(int $userId, string $ability, string $scopeType): ?array;
}
