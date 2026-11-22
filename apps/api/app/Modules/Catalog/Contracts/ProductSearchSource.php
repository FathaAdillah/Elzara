<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Contracts;

/** Sumber dokumen indeks produk untuk modul Search; Catalog tetap pemilik tabel (Doc 01 §3.1). */
interface ProductSearchSource
{
    /**
     * @param  list<int>  $productIds
     * @return array<int, array<string, mixed>> dokumen per id produk; id yang hilang berarti produk dihapus
     */
    public function documents(array $productIds): array;

    /** @param callable(list<int>): void $consumer */
    public function eachProductIdChunk(int $size, callable $consumer): void;
}
