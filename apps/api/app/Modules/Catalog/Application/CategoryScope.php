<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Platform\Domain\Scalar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;

/** Kategori yang boleh disentuh satu operator untuk satu aksi; null = global. */
final class CategoryScope
{
    /** @param list<int>|null $ids */
    public function __construct(public readonly ?array $ids) {}

    public static function global(): self
    {
        return new self(null);
    }

    public function isGlobal(): bool
    {
        return $this->ids === null;
    }

    public function covers(mixed $categoryId): bool
    {
        if ($this->ids === null) {
            return true;
        }

        return $categoryId !== null && in_array(Scalar::int($categoryId), $this->ids, true);
    }

    public function intersect(self $other): self
    {
        if ($this->ids === null || $other->ids === null) {
            return $this->ids === null ? $other : $this;
        }

        return new self(array_values(array_intersect($this->ids, $other->ids)));
    }

    public function constrain(Builder $query, string $column): Builder
    {
        return $this->ids === null ? $query : $query->whereIn($column, $this->ids);
    }

    public function assertCovers(mixed $categoryId): void
    {
        if (! $this->covers($categoryId)) {
            throw new AuthorizationException('Kategori di luar cakupan peran Anda.');
        }
    }

    public function assertGlobal(): void
    {
        if ($this->ids !== null) {
            throw new AuthorizationException('Aksi ini memerlukan peran katalog global.');
        }
    }
}
