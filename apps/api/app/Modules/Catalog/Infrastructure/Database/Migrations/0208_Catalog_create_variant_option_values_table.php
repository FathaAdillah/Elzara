<?php

// modul: Catalog | level: L5 | sprint: 1 | tier: 5 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_option_values', function (Blueprint $table): void {
            $table->foreignId('variant_id')->constrained('product_variants', indexName: 'fk_variant_option_values_variant')->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained('product_option_values', indexName: 'fk_variant_option_values_option_value')->restrictOnDelete();
            $table->primary(['variant_id', 'option_value_id']);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('variant_option_values');
    }
};
