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
        Schema::create('product_collections', function (Blueprint $table): void {
            $table->foreignId('product_id')->constrained('products', indexName: 'fk_product_collections_product')->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained('collections', indexName: 'fk_product_collections_collection')->cascadeOnDelete();
            $table->primary(['product_id', 'collection_id']);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('product_collections');
    }
};
