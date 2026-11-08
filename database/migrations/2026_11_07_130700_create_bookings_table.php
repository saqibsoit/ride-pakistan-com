<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rider's confirmed seat in a slot, paid by an order or a package
     * credit. ticket_code is the QR ticket identifier (milestone 8).
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('credit_pack_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('confirmed');
            $table->string('ticket_code', 32)->unique();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['slot_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
