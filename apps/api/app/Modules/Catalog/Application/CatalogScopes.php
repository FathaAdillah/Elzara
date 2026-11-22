<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\CategoryScopes;
use App\Modules\Identity\Contracts\RowScopes;
use App\Platform\Domain\Scalar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Scope `category` dari role_scopes, diperluas ke seluruh turunan kategori. */
final class CatalogScopes implements CategoryScopes
{
    public function __construct(private readonly RowScopes $scopes) {}

    public function for(Request $request, string $ability): CategoryScope
    {
        return new CategoryScope($this->allowedCategoryIds($request->attributes->getInt('admin_actor_id'), $ability));
    }

    public function allowedCategoryIds(int $actorId, string $ability): ?array
    {
        $ids = $this->scopes->allowedIds($actorId, $ability, 'category');
        if ($ids === null || $ids === []) {
            return $ids;
        }
        $rows = DB::select(
            'WITH RECURSIVE tree AS (SELECT id FROM categories WHERE id = ANY(?::bigint[]) UNION SELECT c.id FROM categories c JOIN tree t ON c.parent_id = t.id) SELECT id FROM tree ORDER BY id',
            ['{'.implode(',', $ids).'}'],
        );

        return array_values(array_map(static fn (mixed $row): int => Scalar::int(is_object($row) ? ($row->id ?? null) : null), $rows));
    }
}
