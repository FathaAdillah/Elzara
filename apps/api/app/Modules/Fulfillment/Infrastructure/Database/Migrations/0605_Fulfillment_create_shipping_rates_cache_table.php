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
        Schema::create('shipping_rates_cache', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('cache_key', 160)->unique('uq_shipping_rates_cache_key');
            $table->bigInteger('amount');
            $table->string('provider', 24);
            $table->jsonb('rates');
            $table->timestampTz('fetched_at');
            $table->timestampTz('expires_at');
            $table->index('expires_at', 'idx_rates_expiry');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates_cache');
    }
};
