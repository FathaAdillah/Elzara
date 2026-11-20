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
        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->foreignId('user_id')->unique('uq_customer_profiles_user')->constrained('users', indexName: 'fk_customer_profiles_users')->cascadeOnDelete();
            $table->string('display_name', 120)->nullable();
            $table->date('birthday')->nullable();
            $table->string('gender', 12)->nullable();
            $table->string('tax_id', 40)->nullable();
            $table->string('referral_code', 40)->nullable();
            $table->foreignId('referred_by')->nullable()->constrained('users', indexName: 'fk_customer_profiles_referrer')->nullOnDelete();
            $table->jsonb('external_ids')->default(DB::raw("'{}'::jsonb"));
            $table->text('notes')->nullable();
            $table->index('referral_code', 'idx_customer_profiles_referral');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE customer_profiles ADD CONSTRAINT ck_customer_profiles_gender CHECK (gender IN ('male', 'female', 'other', 'undisclosed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
