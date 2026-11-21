<?php

// modul: Catalog | level: L4 | sprint: 1 | tier: 4 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_option_values', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('option_id')->constrained('product_options', indexName: 'fk_product_option_values_option')->cascadeOnDelete();
            $table->string('value', 60);
            $table->smallInteger('position')->default(0);
            $table->unique(['option_id', 'value'], 'uq_option_value');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_values');
    }
};
