<?php

// modul: Identity | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_addresses_users')->cascadeOnDelete();
            $table->string('label', 60)->nullable();
            $table->string('recipient', 120);
            $table->string('phone', 24);
            $table->string('province', 60);
            $table->string('city', 60);
            $table->string('district', 60)->nullable();
            $table->string('village', 60)->nullable();
            $table->string('postal_code', 10);
            $table->text('address_line');
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->index('user_id', 'idx_addresses_user');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('CREATE UNIQUE INDEX uq_addresses_default ON addresses (user_id) WHERE is_default AND NOT is_archived');
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
