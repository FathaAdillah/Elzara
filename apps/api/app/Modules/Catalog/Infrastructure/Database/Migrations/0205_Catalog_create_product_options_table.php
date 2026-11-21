<?php

// modul: Catalog | level: L3 | sprint: 1 | tier: 3 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_options', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('product_id')->constrained('products', indexName: 'fk_product_options_product')->cascadeOnDelete();
            $table->string('name', 60);
            $table->smallInteger('position')->default(0);
            $table->unique(['product_id', 'name'], 'uq_product_options_name');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('product_options');
    }
};
