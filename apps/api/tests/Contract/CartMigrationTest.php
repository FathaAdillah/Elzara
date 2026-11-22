<?php

declare(strict_types=1);

namespace Tests\Contract;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CartMigrationTest extends TestCase
{
    public function test_schema_constraints_and_rollback(): void
    {
        DB::beginTransaction();
        try {
            $s = 'cart_contract_'.bin2hex(random_bytes(8));
            DB::statement("CREATE SCHEMA {$s}");
            DB::statement("SET LOCAL search_path TO {$s}, public");
            DB::statement('CREATE TABLE users (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            DB::statement('CREATE TABLE product_variants (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
            $files = glob(app_path('Modules/Cart/Infrastructure/Database/Migrations/*.php'));
            $this->assertCount(3, $files);
            foreach ($files as $f) {
                (require $f)->up();
            }
            foreach (['carts' => ['id' => 'int8', 'expires_at' => 'timestamptz', 'converted_order_id' => 'int8'], 'cart_items' => ['unit_price_snapshot' => 'int8', 'batch_offer_id' => 'int8'], 'checkout_sessions' => ['snapshot' => 'jsonb', 'subtotal' => 'int8', 'shipping_estimate' => 'int8']] as $table => $types) {
                $cols = collect(Schema::getColumns($table))->keyBy('name');
                foreach ($types as $name => $type) {
                    $this->assertSame($type, $cols[$name]['type_name']);
                }
            }
            $this->rejects('23514', fn () => DB::table('carts')->insert(['expires_at' => now()]));
            $u = DB::table('users')->insertGetId([]);
            $v = DB::table('product_variants')->insertGetId([]);
            $c = DB::table('carts')->insertGetId(['user_id' => $u, 'expires_at' => now()]);
            $this->rejects('23505', fn () => DB::table('carts')->insert(['user_id' => $u, 'expires_at' => now()]));
            $this->rejects('23514', fn () => DB::table('carts')->where('id', $c)->update(['status' => 'wrong']));
            DB::table('cart_items')->insert(['cart_id' => $c, 'variant_id' => $v, 'qty' => 1, 'unit_price_snapshot' => 0, 'batch_offer_id' => 999]);
            $this->rejects('23514', fn () => DB::table('cart_items')->update(['qty' => 0]));
            $this->rejects('23505', fn () => DB::table('cart_items')->insert(['cart_id' => $c, 'variant_id' => $v, 'qty' => 1, 'unit_price_snapshot' => 0, 'batch_offer_id' => 999]));
            $this->rejects('23503', fn () => DB::table('product_variants')->where('id', $v)->delete());
            $this->rejects('23514', fn () => DB::table('checkout_sessions')->insert(['code' => 'x', 'expires_at' => now(), 'exchange_rate_snapshot' => 0]));
            $this->rejects('23514', fn () => DB::table('checkout_sessions')->insert(['code' => 'x', 'expires_at' => now(), 'status' => 'bad']));
            DB::table('carts')->where('id', $c)->delete();
            $this->assertSame(0, DB::table('cart_items')->count());
            foreach (array_reverse($files) as $f) {
                (require $f)->down();
            }
            foreach (['carts', 'cart_items', 'checkout_sessions'] as $t) {
                $this->assertFalse(Schema::hasTable($s.'.'.$t));
            }
        } finally {
            DB::rollBack();
        }
    }

    private function rejects(string $state, Closure $fn): void
    {
        try {
            DB::transaction($fn);
            $this->fail('Expected '.$state);
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
        }
    }
}
