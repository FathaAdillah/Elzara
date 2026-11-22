<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Entitas katalog sederhana yang CRUD-nya seragam: kategori, brand, koleksi, price list. */
final class CatalogResources
{
    public const SLUG = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    /** @var array<string, array{table: string, columns: list<string>}> */
    private const DEFINITIONS = [
        'categories' => ['table' => 'categories', 'columns' => ['slug', 'name_key', 'parent_id', 'image_alt_key', 'position', 'is_active']],
        'brands' => ['table' => 'brands', 'columns' => ['slug', 'name', 'country', 'description_key', 'is_active']],
        'collections' => ['table' => 'collections', 'columns' => ['slug', 'name_key', 'kind', 'description_key', 'is_active']],
        'price-lists' => ['table' => 'price_lists', 'columns' => ['code', 'name', 'currency_code', 'kind', 'valid_from', 'valid_to', 'is_active']],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function table(string $resource): string
    {
        return self::definition($resource)['table'];
    }

    /** @return list<string> */
    public static function columns(string $resource): array
    {
        return self::definition($resource)['columns'];
    }

    /** @return array<string, mixed> */
    public static function rules(string $resource, ?int $id = null): array
    {
        $table = self::table($resource);
        $required = $id === null ? 'required' : 'sometimes';
        $unique = static fn (string $column): mixed => $id === null ? Rule::unique($table, $column) : Rule::unique($table, $column)->ignore($id);
        $rules = match ($resource) {
            'categories' => [
                'slug' => [$required, 'string', 'max:80', 'regex:'.self::SLUG, $unique('slug')],
                'name_key' => [$required, 'string', 'max:96'],
                'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id'), ...($id === null ? [] : [Rule::notIn([$id])])],
                'image_alt_key' => ['sometimes', 'nullable', 'string', 'max:96'],
                'position' => ['sometimes', 'integer', 'between:0,32767'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'brands' => [
                'slug' => [$required, 'string', 'max:80', 'regex:'.self::SLUG, $unique('slug')],
                'name' => [$required, 'string', 'max:120'],
                'country' => ['sometimes', 'nullable', 'string', 'regex:/\A[A-Z]{2}\z/'],
                'description_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'collections' => [
                'slug' => [$required, 'string', 'max:80', 'regex:'.self::SLUG, $unique('slug')],
                'name_key' => [$required, 'string', 'max:96'],
                'kind' => ['sometimes', 'string', Rule::in(['fandom', 'ip', 'kolaborasi', 'tema'])],
                'description_key' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            'price-lists' => [
                'code' => [$required, 'string', 'max:32', 'regex:/\A[A-Z0-9_-]+\z/', $unique('code')],
                'name' => [$required, 'string', 'max:120'],
                'currency_code' => ['sometimes', 'string', 'regex:/\A[A-Z]{3}\z/'],
                'kind' => ['sometimes', 'string', Rule::in(['retail', 'wholesale', 'member', 'contract'])],
                'valid_from' => ['sometimes', 'nullable', 'date'],
                'valid_to' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
                'is_active' => ['sometimes', 'boolean'],
            ],
            default => throw new InvalidArgumentException("Resource katalog '{$resource}' tidak dikenal."),
        };
        $rules['reason'] = ['sometimes', 'string', 'min:5', 'max:320'];
        if ($id !== null) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }

    /** @return array{table: string, columns: list<string>} */
    private static function definition(string $resource): array
    {
        return self::DEFINITIONS[$resource] ?? throw new InvalidArgumentException("Resource katalog '{$resource}' tidak dikenal.");
    }
}
