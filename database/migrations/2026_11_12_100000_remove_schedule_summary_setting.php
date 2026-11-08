<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The "Schedule summary" setting was never shown anywhere: schedule
 * wording is generated from the schedule rules (BusinessFacts). Removed
 * in milestone 10a; drop any stored value.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'schedule.summary')->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the value was unused.
    }
};
