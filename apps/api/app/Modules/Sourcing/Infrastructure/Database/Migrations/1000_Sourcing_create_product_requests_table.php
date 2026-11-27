<?php

// modul: Sourcing | level: L3 | sprint: 5 | tier: 5 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_requests', function (Blueprint $table): void {
            $table->id()->generatedAs()->always();
            $table->string('code', 32)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('product_name_wanted', 240);
            $table->string('brand', 120)->nullable();
            $table->string('series', 120)->nullable();
            $table->string('character_name', 120)->nullable();
            $table->string('target_url', 500)->nullable();
            $table->char('origin_country', 2)->nullable();
            $table->string('preferred_mode', 16)->nullable();
            $table->integer('desired_qty');
            $table->bigInteger('budget_max')->nullable();
            $table->char('currency_code', 3)->default('IDR');
            $table->foreignId('reference_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('new');
            $table->timestampTz('sla_due_at')->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->index(['requested_at', 'id'], 'product_requests_recent');
            $table->index(['status', 'sla_due_at'], 'product_requests_status_sla');
            $table->index(['user_id', 'requested_at', 'id'], 'product_requests_user');
        });

        DB::statement("ALTER TABLE product_requests
            ADD CONSTRAINT ck_product_requests_quantity CHECK (desired_qty BETWEEN 1 AND 999),
            ADD CONSTRAINT ck_product_requests_budget CHECK (budget_max >= 0),
            ADD CONSTRAINT ck_product_requests_mode CHECK (preferred_mode IN ('ready_stock', 'preorder')),
            ADD CONSTRAINT ck_product_requests_status CHECK (status IN ('new', 'in_review', 'quoted', 'accepted', 'declined', 'expired', 'withdrawn'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_requests');
    }
};
