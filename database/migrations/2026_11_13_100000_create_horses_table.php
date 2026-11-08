<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Horses (milestone 10b): who can carry which riders, and how often a day.
 * Retired horses are kept for the history of the bookings they carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('photo')->nullable();
            $table->text('temperament_notes')->nullable();
            $table->json('suitable_levels');
            $table->unsignedTinyInteger('max_sessions_per_day')->default(2);
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('retired_at')->nullable()->index();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('horse_unavailabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('horse_id')->constrained()->cascadeOnDelete();
            $table->date('date')->comment('Pakistan date');
            $table->string('reason', 20);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['horse_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horse_unavailabilities');
        Schema::dropIfExists('horses');
    }
};
