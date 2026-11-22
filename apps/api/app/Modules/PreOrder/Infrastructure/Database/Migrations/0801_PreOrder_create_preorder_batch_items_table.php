<?php

// modul: PreOrder | level: L4 | sprint: 4 | tier: 4 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preorder_batch_items', function (Blueprint $t): void {
            $t->id()->generatedAs()->always();
            $t->string('public_id', 40)->unique('uq_preorder_batch_items_public_id');
            $t->foreignId('batch_id')->constrained('preorder_batches', indexName: 'fk_preorder_batch_items_batch')->restrictOnDelete();
            $t->foreignId('variant_id')->constrained('product_variants', indexName: 'fk_preorder_batch_items_variant')->restrictOnDelete();
            $t->integer('quota');
            $t->integer('reserved_qty')->default(0);
            $t->integer('max_per_account')->nullable();
            $t->bigInteger('price');
            $t->char('currency_code', 3)->default('IDR');
            $t->timestampTz('price_valid_until')->nullable();
            $t->string('status', 16)->default('open');
            $t->timestampTz('opens_at')->nullable();
            $t->timestampTz('closes_at')->nullable();
            $t->char('origin_country', 2)->nullable();
            $t->string('origin_shop', 120)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['batch_id', 'variant_id'], 'uq_preorder_batch_items_slot');
        });
        DB::statement("ALTER TABLE preorder_batch_items ADD CONSTRAINT ck_preorder_batch_items_quota CHECK (quota >= 0 AND reserved_qty >= 0 AND reserved_qty <= quota AND price >= 0 AND (max_per_account IS NULL OR max_per_account > 0) AND status IN ('open','closed'))");
        DB::statement('CREATE INDEX idx_preorder_batch_items_variant ON preorder_batch_items (variant_id) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('preorder_batch_items');
    }
};
