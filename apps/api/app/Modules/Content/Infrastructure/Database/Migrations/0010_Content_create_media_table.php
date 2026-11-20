<?php

// modul: Content | level: L0 | sprint: 0 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique('uq_media_uuid');
            $table->string('model_type', 64);
            $table->bigInteger('model_id');
            $table->string('kind', 24); // Klasifikasi berkas, bukan pembeda populasi lama.
            $table->string('disk', 24)->default('s3');
            $table->string('path', 512);
            $table->string('mime', 80)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('alt_key', 120)->nullable();
            $table->smallInteger('position')->default(0);
            $table->boolean('is_moderated')->default(false);
            $table->string('moderation_status', 16)->default('pending');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['model_type', 'model_id', 'kind', 'position'], 'idx_media_model');
            $table->unique(['disk', 'path'], 'uq_media_path');
        });

        DB::statement("ALTER TABLE media ADD CONSTRAINT ck_media_kind CHECK (kind IN ('image', 'video', 'file', 'icon', 'label_pdf', 'proof', 'attachment'))");
        DB::statement("ALTER TABLE media ADD CONSTRAINT ck_media_moderation_status CHECK (moderation_status IN ('pending', 'approved', 'rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
