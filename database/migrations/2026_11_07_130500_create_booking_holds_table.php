<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seats reserved for an unpaid order until expires_at. Active holds
     * plus confirmed bookings never exceed slot capacity (milestone 5).
     */
    public function up(): void
    {
        Schema::create('booking_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seats');
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->index(['slot_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_holds');
    }
};
