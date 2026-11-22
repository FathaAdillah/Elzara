<?php

// modul: Fulfillment | level: L0 | sprint: 3 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_services', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('courier_code', 32);
            $table->string('service_code', 32);
            $table->char('country', 2)->default('ID');
            $table->string('name', 80);
            $table->boolean('supports_label')->default(false);
            $table->boolean('supports_tracking')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unique(['courier_code', 'service_code', 'country'], 'uq_courier_service');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_services');
    }
};
