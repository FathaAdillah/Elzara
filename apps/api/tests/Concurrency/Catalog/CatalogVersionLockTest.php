<?php

declare(strict_types=1);

namespace Tests\Concurrency\Catalog;

use App\Modules\Catalog\Application\AdminContext;
use App\Modules\Catalog\Application\CategoryScope;
use App\Modules\Catalog\Application\ProductAdminService;
use App\Platform\Domain\Exception\VersionStale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ConcurrencyTestCase;

/** K6: dua admin mengedit produk yang sama; penulis kedua menunggu kunci baris lalu ditolak 409, bukan menimpa. */
final class CatalogVersionLockTest extends ConcurrencyTestCase
{
    public function test_competing_admin_waits_for_the_row_lock_then_gets_version_stale(): void
    {
        $slug = 'lock-'.bin2hex(random_bytes(4));
        $category = DB::table('categories')->insertGetId(['slug' => $slug, 'name_key' => 'catalog.category.lock']);
        $product = DB::table('products')->insertGetId(['slug' => $slug, 'name' => 'Original', 'category_id' => $category, 'origin_country' => 'ID']);
        config(['database.connections.catalog_contender' => config('database.connections.pgsql')]);
        $contender = DB::connection('catalog_contender');
        $service = app(ProductAdminService::class);
        $context = new AdminContext(0, 'Concurrency fixture', 'req-lock', null, 'phpunit');

        try {
            $contender->beginTransaction();
            $contender->table('products')->where('id', $product)->lockForUpdate()->first();
            $contender->table('products')->where('id', $product)->update(['name' => 'First writer', 'version' => 2]);

            DB::statement("SET lock_timeout TO '200ms'");
            try {
                $service->update($product, 1, ['name' => 'Second writer'], $context, CategoryScope::global());
                $this->fail('Penulis kedua harus menunggu kunci baris.');
            } catch (QueryException $failure) {
                $this->assertSame('55P03', $failure->errorInfo[0]);
            } finally {
                DB::statement('RESET lock_timeout');
            }
            $contender->commit();

            $this->expectException(VersionStale::class);
            try {
                $service->update($product, 1, ['name' => 'Second writer'], $context, CategoryScope::global());
            } finally {
                $this->assertSame('First writer', DB::table('products')->where('id', $product)->value('name'));
                $this->assertSame(0, DB::table('outbox_events')->where('aggregate_type', 'product')->where('aggregate_id', $product)->count());
            }
        } finally {
            if ($contender->transactionLevel() > 0) {
                $contender->rollBack();
            }
            DB::purge('catalog_contender');
            DB::table('products')->where('id', $product)->delete();
            DB::table('categories')->where('id', $category)->delete();
        }
    }
}
