<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a slot came from: "rule" (GenerateSlots) or "manual" (an admin).
     * Every existing slot was generated, so the default backfills "rule".
     */
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->string('source', 10)->default('rule')->after('schedule_rule_id');
            $table->index(['source', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->dropIndex(['source', 'starts_at']);
            $table->dropColumn('source');
        });
    }
};
