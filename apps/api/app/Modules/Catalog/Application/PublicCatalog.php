<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Contracts\ProductSearch;
use App\Modules\Content\Contracts\LocalizedText;
use App\Platform\Domain\PublicId;
use App\Platform\Http\CursorPagination;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use stdClass;
use UnexpectedValueException;

final class PublicCatalog
{
    private const MODES = ['rts' => 'rts', 'po' => 'pre_order', 'request' => 'request_order'];

    public function __construct(private readonly ProductSearch $search, private readonly LocalizedText $text) {}

    private function variants(): Builder
    {
        return DB::table('product_variants as v')->where('v.is_active', true)->whereNull('v.deleted_at')
            ->select('v.*')->selectRaw("COALESCE((SELECT e.price FROM price_list_entries e JOIN price_lists l ON l.id = e.price_list_id WHERE e.variant_id = v.id AND l.is_active AND l.kind = 'retail' AND l.currency_code = v.currency_code AND e.currency_code = v.currency_code AND (l.valid_from IS NULL OR l.valid_from <= CURRENT_TIMESTAMP) AND (l.valid_to IS NULL OR l.valid_to > CURRENT_TIMESTAMP) ORDER BY l.valid_from DESC NULLS LAST, l.id DESC LIMIT 1), v.base_price) AS price")
            ->selectRaw('COALESCE((SELECT SUM(GREATEST(s.available_to_sell, 0)) FROM stock_items s WHERE s.variant_id = v.id), 0) AS available');
    }

    private function products(): Builder
    {
        $totals = DB::query()->fromSub($this->variants(), 'variants')->select('product_id')
            ->selectRaw('MIN(price) AS price, SUM(available) AS available')->groupBy('product_id');

        return DB::table('products as p')->joinSub($totals, 'totals', 'totals.product_id', '=', 'p.id')
            ->leftJoin('brands as b', 'b.id', '=', 'p.brand_id')->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.status', 'active')->whereNull('p.deleted_at')
            ->select('p.*', 'b.name as brand', 'c.slug as category_slug', 'c.name_key as category_name', 'totals.price', 'totals.available');
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listing(Request $request, array $filters): array
    {
        $query = $this->products();
        if (isset($filters['q']) && is_string($filters['q'])) {
            $matches = $this->search->search($filters['q']);
            $query->whereIn('p.id', array_keys($matches));
            $cases = 'CASE p.id';
            $bindings = [];
            foreach ($matches as $id => $score) {
                $cases .= ' WHEN ? THEN ?::double precision';
                array_push($bindings, $id, $score);
            }
            $query->selectRaw($matches === [] ? '0 AS relevance' : $cases.' ELSE 0 END AS relevance', $bindings);
        }
        if (isset($filters['kind'])) {
            $query->whereRaw('? = ANY(p.fulfillment_modes)', [array_search($filters['kind'], self::MODES, true)]);
        }
        if (isset($filters['brand'])) {
            $query->where('b.slug', $filters['brand']);
        }
        if (isset($filters['category'])) {
            $query->whereRaw('p.category_id IN (WITH RECURSIVE tree AS (SELECT id FROM categories WHERE slug = ? UNION SELECT c.id FROM categories c JOIN tree t ON c.parent_id = t.id) SELECT id FROM tree)', [$filters['category']]);
        }
        if (isset($filters['collection'])) {
            $query->whereIn('p.id', DB::table('product_collections as pc')->join('collections as col', 'col.id', '=', 'pc.collection_id')->where('col.slug', $filters['collection'])->select('pc.product_id'));
        }
        $query = DB::query()->fromSub($query, 'catalog');
        foreach (['price_min' => '>=', 'price_max' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->where('price', $operator, $filters[$key]);
            }
        }
        if (isset($filters['in_stock'])) {
            $query->where('available', filter_var($filters['in_stock'], FILTER_VALIDATE_BOOLEAN) ? '>' : '=', 0);
        }
        $sort = match ($filters['sort'] ?? 'newest') {
            'price_asc' => ['price' => 'asc', 'id' => 'asc'],
            'price_desc' => ['price' => 'desc', 'id' => 'desc'],
            'relevance' => ['relevance' => 'desc', 'id' => 'desc'],
            default => ['created_at' => 'desc', 'id' => 'desc'],
        };
        unset($filters['limit'], $filters['cursor']);
        $page = CursorPagination::paginate($request, $query, $sort, $filters);

        return ['data' => array_map($this->summary(...), $page['data']), 'meta' => $page['meta']];
    }

    /** @return array<string, mixed> */
    public function detail(string $slug): array
    {
        $product = $this->products()->where('p.slug', $slug)->first();
        abort_if($product === null, 404);
        $result = $this->summary($product);
        $description = $this->text($product, 'description');
        $result['description_html'] = $description === '' ? '' : '<p>'.str_replace(["\r\n", "\r", "\n"], '<br>', htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</p>';
        $result['variants'] = $this->variants()->where('v.product_id', $product->id)->orderBy('v.id')->get()->map(function (stdClass $variant): array {
            $attributes = DB::table('variant_option_values as pivot')->join('product_option_values as val', 'val.id', '=', 'pivot.option_value_id')->join('product_options as opt', 'opt.id', '=', 'val.option_id')->where('pivot.variant_id', $variant->id)->orderBy('opt.position')->orderBy('opt.id')->pluck('val.value', 'opt.name')->all();

            return ['id' => PublicId::encode('var', $this->integer($variant, 'id')), 'sku' => $variant->sku, 'label' => $variant->label, 'price' => $this->money($this->integer($variant, 'price')), 'available' => $this->integer($variant, 'available'), 'attributes' => (object) $attributes];
        })->all();
        $result['media'] = $this->media($this->integer($product, 'id'));
        $result['options'] = DB::table('product_options')->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get()->map(static fn (stdClass $option): array => ['name' => $option->name, 'values' => DB::table('product_option_values')->where('option_id', $option->id)->orderBy('position')->orderBy('id')->pluck('value')->all()])->all();
        foreach (['meta_title', 'meta_description', 'care_notes', 'return_policy_notes', 'origin_country'] as $field) {
            $result[$field] = $product->{$field};
        }
        $result['collections'] = DB::table('collections as c')->join('product_collections as pc', 'pc.collection_id', '=', 'c.id')->where('pc.product_id', $product->id)->where('c.is_active', true)->orderBy('c.slug')->get(['c.slug', 'c.name_key'])->all();

        return $result;
    }

    /** @return array<string, mixed> */
    private function summary(stdClass $product): array
    {
        $modes = explode(',', trim($this->text($product, 'fulfillment_modes'), '{}'));
        $kinds = array_values(array_intersect_key(self::MODES, array_flip($modes)));

        return ['id' => PublicId::encode('prd', $this->integer($product, 'id')), 'slug' => $product->slug, 'name' => $product->name, 'brand' => $product->brand, 'price' => $this->money($this->integer($product, 'price')), 'available' => $this->integer($product, 'available'), 'kind' => $kinds[0] ?? 'rts', 'kinds' => $kinds, 'primary_media' => $this->media($this->integer($product, 'id'), true)[0] ?? null, 'category' => ['slug' => $product->category_slug, 'name' => $this->text->value($this->text($product, 'category_name'), 'shop')], 'eta' => null];
    }

    /** @return array{amount_minor: int, currency: string} */
    private function money(int $price): array
    {
        return ['amount_minor' => $price, 'currency' => 'IDR'];
    }

    /** @return list<array<string, mixed>> */
    private function media(int $product, bool $primary = false): array
    {
        $query = DB::table('product_media as pm')->join('media as m', 'm.id', '=', 'pm.media_id')->where('pm.product_id', $product)->where('m.disk', 'product-media');
        if ($primary) {
            $query->orderByRaw("CASE WHEN pm.role = 'primary' THEN 0 ELSE 1 END")->limit(1);
        }

        return array_values($query->orderBy('pm.position')->orderBy('pm.id')->get(['m.id', 'm.path', 'm.alt_key'])->map(fn (stdClass $media): array => ['id' => PublicId::encode('media', $this->integer($media, 'id')), 'url' => rtrim(Config::string('filesystems.s3.presign_endpoint'), '/').'/product-media/'.implode('/', array_map(rawurlencode(...), explode('/', $this->text($media, 'path')))), 'alt' => $media->alt_key === null ? null : $this->text->value($this->text($media, 'alt_key'), 'shop')])->all());
    }

    /** @return list<stdClass> */
    public function categories(): array
    {
        return array_values(DB::query()->fromRaw('(WITH RECURSIVE tree AS (SELECT id, slug, name_key, parent_id, position FROM categories WHERE parent_id IS NULL AND is_active UNION ALL SELECT c.id, c.slug, c.name_key, c.parent_id, c.position FROM categories c JOIN tree t ON c.parent_id = t.id WHERE c.is_active) SELECT t.slug, t.name_key, p.slug AS parent, t.position FROM tree t LEFT JOIN categories p ON p.id = t.parent_id) AS nav')->orderBy('position')->orderBy('slug')->get()->all());
    }

    private function integer(stdClass $row, string $field): int
    {
        $value = $row->{$field};
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1 && filter_var($value, FILTER_VALIDATE_INT) !== false) {
            return (int) $value;
        }
        throw new UnexpectedValueException('Invalid integer column: '.$field);
    }

    private function text(stdClass $row, string $field): string
    {
        $value = $row->{$field};
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        throw new UnexpectedValueException('Invalid text column: '.$field);
    }
}
