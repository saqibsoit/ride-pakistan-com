<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who runs a session (milestone 10b). A schedule rule's trainer is the
 * default for the slots it generates; an admin can change it per slot.
 * Trainers see only the slots assigned to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->foreignId('trainer_id')->nullable()->after('capacity')->constrained('users')->nullOnDelete();
        });

        Schema::table('slots', function (Blueprint $table): void {
            $table->foreignId('trainer_id')->nullable()->after('private_only')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trainer_id');
        });

        Schema::table('schedule_rules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trainer_id');
        });
    }
};
