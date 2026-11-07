<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook idempotency: each Stripe event id is handled once (PLAN §3.1).
     */
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type', 100)->index();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
