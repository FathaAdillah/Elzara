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
        Schema::create('price_list_entries', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('price_list_id')->constrained('price_lists', indexName: 'fk_price_list_entries_price_list')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants', indexName: 'fk_price_list_entries_variant')->cascadeOnDelete();
            $table->bigInteger('price');
            $table->char('currency_code', 3)->default('IDR');
            $table->unique(['price_list_id', 'variant_id'], 'uq_price_list_entry');
            $table->index('variant_id', 'idx_price_list_entries_variant');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE price_list_entries ADD CONSTRAINT ck_price_list_entries_price CHECK (price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_entries');
    }
};
