<?php

// modul: Platform | level: L2 | sprint: 0 | tier: 2 | expand: ya | contract: tidak ada

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->string('request_id', 128)->nullable();
            $table->char('trace_id', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table): void {
            $table->dropColumn(['request_id', 'trace_id']);
        });
    }
};
