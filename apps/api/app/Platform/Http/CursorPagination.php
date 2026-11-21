<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use stdClass;

final class CursorPagination
{
    /**
     * @param  array<string, 'asc'|'desc'>  $sort
     * @param  array<string, mixed>  $filters
     * @return array{data: list<stdClass>, meta: array{limit: int, has_more: bool, next_cursor: ?string, prev_cursor: ?string}}
     */
    public static function paginate(Request $request, Builder $query, array $sort = ['created_at' => 'desc', 'id' => 'desc'], array $filters = []): array
    {
        foreach (['offset', 'page'] as $field) {
            if ($request->query->has($field)) {
                throw ValidationException::withMessages([$field => 'Gunakan cursor, bukan offset/page.']);
            }
        }
        $rawLimit = $request->query->has('limit') ? $request->query->all()['limit'] : '20';
        $limit = is_scalar($rawLimit) ? filter_var($rawLimit, FILTER_VALIDATE_INT) : false;
        if ($limit === false || $limit < 1 || $limit > 100) {
            throw ValidationException::withMessages(['limit' => 'Limit harus bilangan bulat 1–100.']);
        }
        if ($sort === [] || array_key_last($sort) !== 'id') {
            throw new InvalidArgumentException('Urutan cursor harus berakhir dengan id unik.');
        }
        foreach ($sort as $column => $direction) {
            if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', $column) !== 1) {
                throw new InvalidArgumentException('Kunci urut cursor tidak valid.');
            }
        }
        $hash = hash('sha256', self::canonical($filters));
        $cursor = null;
        $backwards = false;
        if ($request->query->has('cursor')) {
            $cursor = self::decode($request, $sort, $hash);
            $backwards = $cursor['before'];
        }
        $base = (clone $query)->reorder();
        $page = clone $base;
        if ($cursor !== null) {
            self::seek($page, $sort, $cursor['values'], $backwards);
        }
        foreach ($sort as $column => $direction) {
            $page->orderBy($column, $backwards ? ($direction === 'asc' ? 'desc' : 'asc') : $direction);
        }
        /** @var list<stdClass> $rows */
        $rows = $page->limit($limit)->get()->all();
        if ($backwards) {
            $rows = array_reverse($rows);
        }
        $next = null;
        $prev = null;
        if ($rows !== []) {
            $first = self::values($rows[0], $sort);
            $last = self::values($rows[array_key_last($rows)], $sort);
            $after = clone $base;
            self::seek($after, $sort, $last, false);
            if ($after->exists()) {
                $next = self::encode($last, false, $sort, $hash);
            }
            $before = clone $base;
            self::seek($before, $sort, $first, true);
            if ($before->exists()) {
                $prev = self::encode($first, true, $sort, $hash);
            }
        }

        return ['data' => $rows, 'meta' => ['limit' => $limit, 'has_more' => $next !== null, 'next_cursor' => $next, 'prev_cursor' => $prev]];
    }

    /** @param array<string, 'asc'|'desc'> $sort
     * @param  list<int|float|string>  $values
     */
    private static function seek(Builder $query, array $sort, array $values, bool $before): void
    {
        $columns = array_keys($sort);
        $query->where(function (Builder $nested) use ($sort, $values, $before, $columns): void {
            foreach ($columns as $index => $column) {
                $nested->orWhere(function (Builder $branch) use ($sort, $values, $before, $columns, $index, $column): void {
                    for ($prior = 0; $prior < $index; $prior++) {
                        $branch->where($columns[$prior], '=', $values[$prior]);
                    }
                    $greater = ($sort[$column] === 'asc') !== $before;
                    $branch->where($column, $greater ? '>' : '<', $values[$index]);
                });
            }
        });
    }

    /** @param array<string, 'asc'|'desc'> $sort
     * @return list<int|float|string>
     */
    private static function values(stdClass $row, array $sort): array
    {
        $values = [];
        foreach (array_keys($sort) as $column) {
            $value = $row->{$column} ?? null;
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new InvalidArgumentException('Kunci cursor harus dipilih dan tidak boleh null.');
            }
            $values[] = $value;
        }

        return $values;
    }

    /** @param list<int|float|string> $values
     * @param  array<string, 'asc'|'desc'>  $sort
     */
    private static function encode(array $values, bool $before, array $sort, string $hash): string
    {
        $payload = json_encode(['values' => $values, 'before' => $before, 'sort' => $sort, 'filters' => $hash], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload.'.'.hash_hmac('sha256', $payload, Config::string('app.key'))), '+/', '-_'), '=');
    }

    /** @param array<string, 'asc'|'desc'> $sort
     * @return array{values: list<int|float|string>, before: bool}
     */
    private static function decode(Request $request, array $sort, string $hash): array
    {
        $raw = $request->query('cursor');
        $decoded = is_string($raw) && strlen($raw) <= 8192 && preg_match('/\A[A-Za-z0-9_-]+\z/', $raw) === 1
            ? base64_decode(strtr($raw, '-_', '+/'), true) : false;
        $data = null;
        if (is_string($decoded) && strlen($decoded) > 65) {
            $payload = substr($decoded, 0, -65);
            $signature = substr($decoded, -64);
            if (hash_equals(hash_hmac('sha256', $payload, Config::string('app.key')), $signature)) {
                try {
                    $data = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $data = null;
                }
            }
        }
        if (! is_array($data) || ($data['sort'] ?? null) !== $sort || ! is_bool($data['before'] ?? null)
            || ! is_array($data['values'] ?? null) || ! array_is_list($data['values']) || count($data['values']) !== count($sort)
            || ! is_string($data['filters'] ?? null)) {
            self::fail($request, 'cursor-invalid');
        }
        foreach ($data['values'] as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                self::fail($request, 'cursor-invalid');
            }
        }
        if ($data['filters'] !== $hash) {
            self::fail($request, 'cursor-filter-mismatch');
        }

        return ['values' => $data['values'], 'before' => $data['before']];
    }

    private static function fail(Request $request, string $slug): never
    {
        throw new HttpResponseException(ApiProblem::make('pagination', $slug, 'Cursor tidak valid', 400, strtoupper(str_replace('-', '_', $slug)))
            ->forRequest($request, AttachRequestId::getOrAssign($request))->toResponse());
    }

    private static function canonical(mixed $value): string
    {
        if (! is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return json_encode(array_map(self::canonical(...), $value), JSON_THROW_ON_ERROR);
    }
}
