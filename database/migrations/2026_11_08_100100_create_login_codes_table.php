<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time sign-in codes (PLAN §7). Only an HMAC of the code is stored.
     * "channel" + "destination" keep the table ready for phone/WhatsApp codes.
     */
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 20);
            $table->string('destination', 190);
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at')->index();
            $table->dateTime('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['channel', 'destination', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
