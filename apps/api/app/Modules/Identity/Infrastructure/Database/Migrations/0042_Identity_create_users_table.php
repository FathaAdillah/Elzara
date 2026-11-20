<?php

// modul: Identity | level: L1 | sprint: 0 | tier: 1 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        Schema::create('users', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('public_id', 26)->unique('uq_users_public_id');
            $table->string('name', 120);
            $table->string('email')->unique('uq_users_email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('phone', 24)->nullable();
            $table->timestampTz('phone_verified_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->boolean('is_operator')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->string('status', 16)->default('active');
            $table->string('locale', 5)->default('id');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->foreignId('avatar_media_id')->nullable()->constrained('media', indexName: 'fk_users_avatar_media')->nullOnDelete();
            $table->foreignId('banner_media_id')->nullable()->constrained('media', indexName: 'fk_users_banner_media')->nullOnDelete();
            $table->boolean('marketing_optin')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->softDeletesTz();
            $table->index(['is_operator', 'status'], 'users_operator_status');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement('ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator CHECK (NOT is_admin OR is_operator)');
        DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_status CHECK (status IN ('active', 'suspended', 'deletion_requested', 'deleted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
