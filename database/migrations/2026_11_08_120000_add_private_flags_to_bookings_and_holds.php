<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Private sessions block the whole slot (PLAN §2), even after payment
     * when fewer riders than the capacity are booked, so holds and bookings
     * record whether they are private. Plus an index for the
     * "one rider once per slot" check.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->boolean('is_private')->default(false)->after('credit_pack_id');
            $table->index(['slot_id', 'rider_id']);
        });

        Schema::table('booking_holds', function (Blueprint $table): void {
            $table->boolean('is_private')->default(false)->after('seats');
        });
    }

    public function down(): void
    {
        Schema::table('booking_holds', function (Blueprint $table): void {
            $table->dropColumn('is_private');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropIndex(['slot_id', 'rider_id']);
            $table->dropColumn('is_private');
        });
    }
};
