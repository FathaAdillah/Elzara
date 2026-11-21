<?php

// modul: Catalog | level: L4 | sprint: 1 | tier: 4 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_media', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('product_id')->constrained('products', indexName: 'fk_product_media_product')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media', indexName: 'fk_product_media_media')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants', indexName: 'fk_product_media_variant')->nullOnDelete();
            $table->string('role', 16)->default('gallery');
            $table->smallInteger('position')->default(0);
            $table->unique(['product_id', 'media_id'], 'uq_product_media_slot');
            $table->index(['product_id', 'role', 'position'], 'idx_product_media_product');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE product_media ADD CONSTRAINT ck_product_media_role CHECK (role IN ('primary', 'gallery', 'detail', 'size_chart'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
    }
};
