<?php

// modul: Catalog | level: L1 | sprint: 1 | tier: 1 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('slug', 80)->unique('uq_categories_slug');
            $table->string('name_key', 96);
            $table->foreignId('parent_id')->nullable()->constrained('categories', indexName: 'fk_categories_parent')->nullOnDelete();
            $table->foreignId('icon_media_id')->nullable()->constrained('media', indexName: 'fk_categories_icon_media')->nullOnDelete();
            $table->string('image_alt_key', 96)->nullable();
            $table->smallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->index(['parent_id', 'position'], 'idx_categories_tree');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE categories ADD CONSTRAINT ck_categories_parent CHECK (parent_id <> id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
