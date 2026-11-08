<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * iCalendar SEQUENCE of a booking (milestone 10d): raised each time the
 * booking moves, so the updated .ics replaces the one already imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->unsignedInteger('calendar_sequence')->default(0)->after('ticket_code');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('calendar_sequence');
        });
    }
};
