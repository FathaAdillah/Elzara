<?php

// modul: Catalog | level: L1 | sprint: 1 | tier: 1 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('slug', 80)->unique('uq_brands_slug');
            $table->string('name', 120);
            $table->foreignId('logo_media_id')->nullable()->constrained('media', indexName: 'fk_brands_logo_media')->nullOnDelete();
            $table->string('country', 2)->nullable();
            $table->text('description_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->index('is_active', 'idx_brands_active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
