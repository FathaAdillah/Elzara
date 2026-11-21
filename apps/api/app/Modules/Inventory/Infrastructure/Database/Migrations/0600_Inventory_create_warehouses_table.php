<?php

// modul: Inventory | level: L0 | sprint: 3 | tier: 0 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 24)->unique('uq_warehouses_code');
            $table->string('name', 120);
            $table->string('kind', 16);
            $table->char('country', 2)->default('CN');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE warehouses ADD CONSTRAINT ck_warehouses_kind CHECK (kind IN ('overseas_hub', 'in_transit', 'warehouse', 'supplier'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
