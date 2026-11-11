<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's WhatsApp number (E.164), asked for at booking (prompt 14)
 * and shown to the team on the run sheet, the order page and exports.
 * Kept apart from `phone`: it can be the same number or another one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('whatsapp_phone', 20)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_phone');
        });
    }
};
