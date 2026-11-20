<?php

// modul: Settings | level: L3 | sprint: 0 | tier: 3 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flag_overrides', function (Blueprint $table): void {
            $table->string('flag_code', 64);
            $table->string('env', 16)->default('prod');
            $table->boolean('enabled');
            $table->smallInteger('rollout_percent')->default(100);
            $table->foreignId('updated_by')->constrained('users', indexName: 'fk_feature_flag_overrides_users');
            $table->foreign('flag_code', 'fk_feature_flag_overrides_feature_flags')->references('code')->on('feature_flags')->cascadeOnDelete();
            $table->primary(['flag_code', 'env'], 'pk_feature_flag_overrides');
        });

        DB::statement("ALTER TABLE feature_flag_overrides ADD CONSTRAINT ck_feature_flag_overrides_env CHECK (env IN ('local', 'staging', 'prod'))");
        DB::statement('ALTER TABLE feature_flag_overrides ADD CONSTRAINT ck_feature_flag_overrides_rollout CHECK (rollout_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flag_overrides');
    }
};
