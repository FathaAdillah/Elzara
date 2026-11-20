<?php

// modul: Content | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localizations', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('key', 140);
            $table->string('group_name', 60);
            $table->string('locale', 5);
            $table->text('value');
            $table->string('context', 120)->nullable();
            $table->smallInteger('plural_variant')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'fk_localizations_users');
            $table->index(['group_name', 'locale'], 'idx_localization_group');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('CREATE UNIQUE INDEX uq_localization_key_locale ON localizations (key, locale, COALESCE(plural_variant, 0))');
        DB::statement("ALTER TABLE localizations ADD CONSTRAINT ck_localizations_locale CHECK (locale IN ('id', 'en'))");
        DB::statement("ALTER TABLE localizations ADD CONSTRAINT ck_localizations_group CHECK (group_name IN ('shop', 'admin', 'email', 'validation', 'policy'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('localizations');
    }
};
