<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\CustomerProfile;
use App\Modules\Identity\Infrastructure\Models\User;
use Database\Seeders\Demo\DemoAccountSeeder;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoEnvironment;
use Database\Seeders\Demo\DemoShippingSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Data uji beban k6 (P0.24, load/k6/README.md): akun pelanggan load.customer.NNNN@elzara.test,
 * produk checkout berstok besar, dan produk kontensi berstok kecil yang di-reset setiap seed.
 */
final class LoadTestSeeder extends Seeder
{
    public const CHECKOUT_SLUG = 'load-checkout-plush';

    public const CONTENTION_SLUG = 'load-contention-plush';

    public function run(): void
    {
        DemoEnvironment::assertNotProduction();
        $password = (string) env('LOADTEST_PASSWORD', '');
        if (strlen($password) < 12) {
            throw new RuntimeException('LOADTEST_PASSWORD wajib diisi (minimal 12 karakter).');
        }
        $customers = max(1, (int) env('LOADTEST_CUSTOMERS', 50));
        $contentionStock = max(1, (int) env('LOADTEST_CONTENTION_STOCK', 10));

        $this->call([ReferenceSeeder::class, DemoAccountSeeder::class, DemoCatalogSeeder::class, DemoShippingSeeder::class]);

        $hash = password_hash($password, PASSWORD_ARGON2ID);
        for ($i = 1; $i <= $customers; $i++) {
            $email = sprintf('load.customer.%04d@elzara.test', $i);
            $user = User::query()->firstOrCreate(['email' => $email], [
                'public_id' => (string) Str::ulid(), 'name' => sprintf('Load Customer %04d', $i), 'email_verified_at' => now(),
                'password_hash' => $hash, 'is_operator' => false, 'status' => 'active',
            ]);
            $user->forceFill(['password_hash' => $hash])->save();
            CustomerProfile::query()->firstOrCreate(['user_id' => $user->id], ['display_name' => $user->name, 'gender' => 'undisclosed']);
        }

        DB::transaction(function () use ($contentionStock): void {
            $checkout = $this->product(self::CHECKOUT_SLUG, 'Load Checkout Plush', 'LOAD-CHECKOUT');
            DB::table('stock_items')->where('variant_id', $checkout)->update(['on_hand' => 1_000_000]);

            $stale = DB::table('products')->where('slug', self::CONTENTION_SLUG)->value('id');
            if ($stale !== null) {
                $suffix = now()->format('YmdHis');
                DB::table('products')->where('id', $stale)->update(['slug' => self::CONTENTION_SLUG.'-'.$suffix, 'status' => 'archived']);
                DB::table('product_variants')->where('product_id', $stale)->update(['sku' => DB::raw("sku || '-{$suffix}'")]);
            }
            $contention = $this->product(self::CONTENTION_SLUG, 'Load Contention Plush', 'LOAD-CONTENTION');
            DB::table('stock_items')->where('variant_id', $contention)->update(['on_hand' => $contentionStock]);
        });

        $this->command?->info("LoadTestSeeder: {$customers} akun, stok kontensi {$contentionStock}.");
    }

    /** Satu varian RTS per produk; mengembalikan id varian. */
    private function product(string $slug, string $name, string $sku): int
    {
        $product = DB::table('products')->where('slug', $slug)->value('id');
        if ($product === null) {
            $product = DB::table('products')->insertGetId([
                'slug' => $slug, 'name' => $name, 'category_id' => DB::table('categories')->where('slug', 'accessories')->value('id'),
                'status' => 'active', 'origin_country' => 'ID', 'fulfillment_modes' => '{rts}', 'unit_weight_g' => 500,
                'description' => $name."\nProduk data uji beban k6.",
            ]);
            $variant = DB::table('product_variants')->insertGetId(['product_id' => $product, 'sku' => $sku, 'label' => 'Standard', 'base_price' => 100000]);
            DB::table('stock_items')->insert(['warehouse_id' => DB::table('warehouses')->where('code', 'DEMO')->value('id'), 'variant_id' => $variant, 'on_hand' => 0]);

            return (int) $variant;
        }

        return (int) DB::table('product_variants')->where('product_id', $product)->value('id');
    }
}
