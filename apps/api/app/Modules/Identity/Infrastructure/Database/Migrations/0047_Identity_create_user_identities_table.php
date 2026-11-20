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
        Schema::create('user_identities', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_user_identities_users')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('provider_user_id', 120);
            $table->timestampTz('linked_at')->useCurrent();
            $table->unique(['provider', 'provider_user_id'], 'uq_user_identities_provider');
            $table->index('user_id', 'idx_user_identities_user');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE user_identities ADD CONSTRAINT ck_user_identities_provider CHECK (provider IN ('google', 'x'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
