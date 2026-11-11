<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 15A: a rule slot moved by the bulk schedule editor becomes a
 * manual slot and remembers the rule start it replaced, so GenerateSlots
 * doesn't put a new rule slot back at the old time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->dateTime('replaces_starts_at')->nullable()->after('ends_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->dropIndex(['replaces_starts_at']);
            $table->dropColumn('replaces_starts_at');
        });
    }
};
