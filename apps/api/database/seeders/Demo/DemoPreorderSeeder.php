<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DemoPreorderSeeder extends Seeder
{
    public function run(): void
    {
        DemoEnvironment::assertNotProduction();
        DB::transaction(function (): void {
            $now = now();
            DB::table('preorder_batches')->insertOrIgnore([
                'public_id' => 'batch_'.Str::ulid(), 'code' => 'DEMO-NOVA', 'title' => 'Nova Keychain Pre-order', 'status' => 'open',
                'order_window_open_at' => $now->copy()->subDay(), 'order_window_close_at' => $now->copy()->addDays(14),
                'eta_min_date' => $now->copy()->addDays(30)->toDateString(), 'eta_max_date' => $now->copy()->addDays(45)->toDateString(),
                'deposit_kind' => 'percent', 'deposit_value' => 5000, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $batch = DB::table('preorder_batches')->where('code', 'DEMO-NOVA')->value('id');
            $product = DB::table('products')->where('slug', 'nova-keychain')->value('id');
            foreach (DB::table('product_variants')->where('product_id', $product)->orderBy('id')->get() as $variant) {
                DB::table('preorder_batch_items')->insertOrIgnore([
                    'public_id' => 'bitem_'.Str::ulid(), 'batch_id' => $batch, 'variant_id' => $variant->id,
                    'quota' => 5, 'max_per_account' => 2, 'price' => $variant->base_price,
                    'opens_at' => $now->copy()->subDay(), 'closes_at' => $now->copy()->addDays(14), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }
}
