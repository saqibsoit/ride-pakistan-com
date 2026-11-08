<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every customer and team notification (milestone 8): what went to whom
 * about what. Automatic sends carry a dedupe key (unique), so a reminder
 * or expiry warning is never sent twice; admin resends have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            $table->nullableMorphs('subject');
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->foreignId('resent_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->dateTime('queued_at');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'queued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
