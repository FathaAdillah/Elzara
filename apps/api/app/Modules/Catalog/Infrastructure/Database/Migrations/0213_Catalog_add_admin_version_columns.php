<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** K6 (Doc 01 §6.3): entitas katalog yang diedit admin membawa `version`; tulis basi ditolak 409. */
return new class extends Migration
{
    private const TABLES = ['categories', 'brands', 'collections', 'products', 'product_variants', 'price_lists'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN version integer NOT NULL DEFAULT 1");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT ck_{$table}_version CHECK (version >= 1)");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN version");
        }
    }
};
