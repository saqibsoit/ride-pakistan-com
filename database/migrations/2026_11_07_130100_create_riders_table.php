<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People who ride, managed by a user account (a parent can manage
     * children). Emergency contact and notes are encrypted (PLAN §7).
     */
    public function up(): void
    {
        Schema::create('riders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('age_bracket', 20)->nullable();
            $table->string('experience_level', 20)->nullable();
            $table->text('emergency_contact_name')->nullable();
            $table->text('emergency_contact_phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riders');
    }
};
