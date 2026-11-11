<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 15A: one row per customer per slot whose time the bulk schedule
 * editor changed. The email's signed links act on it: keep the new time,
 * move, or cancel (credit back, or a refund request for paid bookings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slot_change_notices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch');
            $table->foreignId('slot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('old_starts_at');
            $table->dateTime('old_ends_at');
            $table->dateTime('new_starts_at');
            $table->dateTime('new_ends_at');
            $table->string('status', 20)->default('pending')->index();
            $table->dateTime('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['batch', 'slot_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slot_change_notices');
    }
};
