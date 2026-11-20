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
        Schema::create('user_consents', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->constrained('users', indexName: 'fk_user_consents_users')->cascadeOnDelete();
            $table->string('kind', 32); // Klasifikasi consent, bukan pembeda populasi lama.
            $table->integer('policy_version');
            $table->timestampTz('granted_at');
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent')->nullable();
            $table->unique(['user_id', 'kind', 'policy_version'], 'uq_user_consents_once');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE user_consents ADD CONSTRAINT ck_user_consents_kind CHECK (kind IN ('terms', 'privacy', 'po_deposit_forfeit', 'marketing', 'age_gate'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
