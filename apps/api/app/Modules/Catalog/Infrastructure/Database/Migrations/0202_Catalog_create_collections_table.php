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
        Schema::create('collections', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('slug', 80)->unique('uq_collections_slug');
            $table->string('name_key', 96);
            $table->string('kind', 24)->default('fandom');
            $table->foreignId('banner_media_id')->nullable()->constrained('media', indexName: 'fk_collections_banner_media')->nullOnDelete();
            $table->text('description_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE collections ADD CONSTRAINT ck_collections_kind CHECK (kind IN ('fandom', 'ip', 'kolaborasi', 'tema'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};
