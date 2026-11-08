<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The horse a rider is on for a session, and the trainer's session note
 * for that rider (milestone 10b). The note is stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('horse_id')->nullable()->after('credit_pack_id')->constrained()->restrictOnDelete();
            $table->text('session_note')->nullable()->after('ticket_code');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('horse_id');
            $table->dropColumn('session_note');
        });
    }
};
