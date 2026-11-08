<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waitlist for full slots (milestone 10d). An offer holds the freed seats
 * with an ordinary booking hold that belongs to the entry instead of an
 * order, so the booking engine's seat counts and row locks cover it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seats');
            $table->string('status', 20)->default('waiting');
            $table->dateTime('offered_at')->nullable();
            $table->dateTime('offer_expires_at')->nullable();
            $table->dateTime('booked_at')->nullable();
            $table->string('closed_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['slot_id', 'status', 'id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'offer_expires_at']);
        });

        Schema::table('booking_holds', function (Blueprint $table): void {
            $table->foreignId('order_id')->nullable()->change();
            $table->foreignId('waitlist_entry_id')->nullable()->after('order_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_holds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('waitlist_entry_id');
        });

        Schema::dropIfExists('waitlist_entries');
    }
};
