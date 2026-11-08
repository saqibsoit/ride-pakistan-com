<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider progress (milestone 10d): the skill checklist admins manage, the
 * skills each rider has shown (ticked by the trainer from the run sheet),
 * and a short progress note per session that the customer sees. The
 * team's own session note (bookings.session_note) stays internal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->nullable()->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });

        Schema::create('rider_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ticked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('achieved_at');
            $table->timestamps();

            $table->unique(['rider_id', 'skill_id']);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('progress_note', 500)->nullable()->after('session_note');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('progress_note');
        });

        Schema::dropIfExists('rider_skills');
        Schema::dropIfExists('skills');
    }
};
