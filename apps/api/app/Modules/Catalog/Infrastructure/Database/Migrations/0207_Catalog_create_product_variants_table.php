<?php

// modul: Catalog | level: L3 | sprint: 1 | tier: 3 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('product_id')->constrained('products', indexName: 'fk_product_variants_product')->cascadeOnDelete();
            $table->string('sku', 64)->unique('uq_product_variants_sku');
            $table->string('label', 160);
            $table->bigInteger('base_price');
            $table->char('currency_code', 3)->default('IDR');
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT ck_product_variants_base_price CHECK (base_price >= 0)');
        DB::statement('CREATE INDEX idx_product_variants_product ON product_variants (product_id) WHERE is_active');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
