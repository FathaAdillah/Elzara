<?php

// modul: Catalog | level: L2 | sprint: 1 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('slug', 120)->unique('uq_products_slug');
            $table->string('name', 200);
            $table->foreignId('brand_id')->nullable()->constrained('brands', indexName: 'fk_products_brand')->nullOnDelete();
            $table->string('series', 120)->nullable();
            $table->foreignId('category_id')->constrained('categories', indexName: 'fk_products_category')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->string('origin_country', 2);
            $table->integer('unit_weight_g')->nullable();
            $table->string('hs_code', 12)->nullable();
            $table->text('description')->nullable();
            $table->text('care_notes')->nullable();
            $table->text('return_policy_notes')->nullable();
            $table->string('meta_title', 120)->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->index(['category_id', 'status'], 'idx_products_cat_status');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT ck_products_origin_country CHECK (origin_country ~ '^[A-Z]{2}$')");
        DB::statement("ALTER TABLE products ADD CONSTRAINT ck_products_status CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement("ALTER TABLE products ADD COLUMN fulfillment_modes varchar(32)[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX idx_products_brand ON products (brand_id) WHERE brand_id IS NOT NULL');
        DB::statement("CREATE INDEX idx_products_status ON products (status) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
