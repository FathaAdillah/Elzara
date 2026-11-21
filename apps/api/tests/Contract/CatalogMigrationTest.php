<?php

declare(strict_types=1);

namespace Tests\Contract;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogMigrationTest extends TestCase
{
    public function test_catalog_schema_constraints_and_every_rollback(): void
    {
        DB::beginTransaction();
        try {
            $schema = 'catalog_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$schema}");
            DB::statement("SET LOCAL search_path TO {$schema}, public");
            DB::statement('CREATE TABLE media (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            $files = glob(app_path('Modules/Catalog/Infrastructure/Database/Migrations/*.php'));
            $this->assertCount(12, $files);
            foreach ($files as $file) {
                (require $file)->up();
            }
            foreach (['categories', 'brands', 'collections', 'products', 'product_options', 'product_option_values', 'product_variants', 'product_media', 'price_lists', 'price_list_entries'] as $table) {
                $columns = collect(Schema::getColumns($table))->keyBy('name');
                $this->assertSame('int8', $columns['id']['type_name']);
                $this->assertSame('timestamptz', $columns['created_at']['type_name']);
                $this->assertSame('timestamptz', $columns['updated_at']['type_name']);
            }
            foreach (['products' => ['origin_country' => 'varchar', 'fulfillment_modes' => '_varchar'], 'product_variants' => ['base_price' => 'int8', 'currency_code' => 'bpchar'], 'price_list_entries' => ['price' => 'int8'], 'price_lists' => ['valid_from' => 'timestamptz']] as $table => $types) {
                $columns = collect(Schema::getColumns($table))->keyBy('name');
                foreach ($types as $column => $type) {
                    $this->assertSame($type, $columns[$column]['type_name']);
                }
            }
            foreach (['categories' => ['uq_categories_slug', 'idx_categories_tree'], 'brands' => ['uq_brands_slug', 'idx_brands_active'], 'collections' => ['uq_collections_slug'], 'products' => ['uq_products_slug', 'idx_products_cat_status', 'idx_products_brand', 'idx_products_status'], 'product_options' => ['uq_product_options_name'], 'product_option_values' => ['uq_option_value'], 'product_variants' => ['uq_product_variants_sku', 'idx_product_variants_product'], 'product_media' => ['uq_product_media_slot', 'idx_product_media_product'], 'price_lists' => ['uq_price_lists_code', 'idx_price_lists_active'], 'price_list_entries' => ['uq_price_list_entry', 'idx_price_list_entries_variant']] as $table => $names) {
                $indexes = collect(Schema::getIndexes($table))->keyBy('name');
                foreach ($names as $name) {
                    $this->assertTrue($indexes->has($name), $name);
                    if (str_starts_with($name, 'uq_')) {
                        $this->assertTrue($indexes[$name]['unique']);
                    }
                }
            }
            $category = DB::table('categories')->insertGetId(['slug' => 'cat', 'name_key' => 'cat']);
            $this->rejects('23514', fn () => DB::table('categories')->where('id', $category)->update(['parent_id' => $category]));
            $product = DB::table('products')->insertGetId(['slug' => 'product', 'name' => 'Product', 'category_id' => $category, 'origin_country' => 'JP']);
            $this->rejects('23503', fn () => DB::table('categories')->where('id', $category)->delete());
            $this->rejects('23503', fn () => DB::table('products')->where('id', $product)->update(['category_id' => 9999]));
            foreach (['origin_country' => 'jp', 'status' => 'unknown'] as $column => $value) {
                $this->rejects('23514', fn () => DB::table('products')->where('id', $product)->update([$column => $value]));
            }
            $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => 'sku', 'label' => 'Variant', 'base_price' => 0]);
            $this->assertSame('IDR', DB::table('product_variants')->value('currency_code'));
            $this->rejects('23514', fn () => DB::table('product_variants')->where('id', $variant)->update(['base_price' => -1]));
            $media = DB::table('media')->insertGetId([]);
            DB::table('product_media')->insert(['product_id' => $product, 'media_id' => $media]);
            $this->rejects('23514', fn () => DB::table('product_media')->update(['role' => 'unknown']));
            $list = DB::table('price_lists')->insertGetId(['code' => 'GENERAL', 'name' => 'General']);
            $this->rejects('23514', fn () => DB::table('price_lists')->where('id', $list)->update(['valid_from' => '2026-01-02', 'valid_to' => '2026-01-01']));
            DB::table('price_list_entries')->insert(['price_list_id' => $list, 'variant_id' => $variant, 'price' => 0]);
            $this->rejects('23514', fn () => DB::table('price_list_entries')->update(['price' => -1]));
            $this->rejects('23505', fn () => DB::table('categories')->insert(['slug' => 'cat', 'name_key' => 'duplicate']));
            foreach (array_reverse($files) as $file) {
                (require $file)->down();
                $table = preg_replace('/^\\d+_Catalog_create_(.*)_table.php$/', '$1', basename($file));
                $this->assertFalse(Schema::hasTable("{$schema}.{$table}"));
            }
        } finally {
            DB::rollBack();
        }
    }

    private function rejects(string $state, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("Expected {$state}");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
