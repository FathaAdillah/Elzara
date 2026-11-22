<?php

// modul: Fulfillment | level: L2 | sprint: 3 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_tariff_rules', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('courier_code', 32);
            $table->string('service_code', 32);
            $table->char('origin_country', 2)->default('ID');
            $table->string('dest_province', 60)->default('*');
            $table->string('package_tier', 24);
            $table->integer('weight_from_g');
            $table->integer('weight_to_g')->nullable();
            $table->bigInteger('base_amount');
            $table->bigInteger('per_500g_amount')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users', indexName: 'fk_shipping_tariff_creator')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['origin_country', 'dest_province', 'courier_code', 'service_code', 'weight_from_g'], 'idx_tariff_lookup');
        });
        DB::statement('ALTER TABLE shipping_tariff_rules ADD CONSTRAINT ck_tariff_weight CHECK (weight_to_g IS NULL OR weight_to_g > weight_from_g)');
        DB::statement('ALTER TABLE shipping_tariff_rules ADD CONSTRAINT ck_tariff_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_tariff_rules');
    }
};
