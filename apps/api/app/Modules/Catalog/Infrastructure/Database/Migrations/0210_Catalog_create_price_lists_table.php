<?php

// modul: Catalog | level: L0 | sprint: 1 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 32)->unique('uq_price_lists_code');
            $table->string('name', 120);
            $table->char('currency_code', 3)->default('IDR');
            $table->string('kind', 16)->default('retail');
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->index(['is_active', 'currency_code'], 'idx_price_lists_active');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE price_lists ADD CONSTRAINT ck_price_lists_validity CHECK (valid_to IS NULL OR valid_to > valid_from)');
        DB::statement("ALTER TABLE price_lists ADD CONSTRAINT ck_price_lists_kind CHECK (kind IN ('retail', 'wholesale', 'member', 'contract'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('price_lists');
    }
};
