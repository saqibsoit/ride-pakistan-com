<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick replies for Instagram DMs (milestone 10c): admin-managed templates
 * whose tokens are filled from live data when copied. `key` marks the
 * templates shipped with the site, so they are seeded once and never
 * overwritten after an admin edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_replies', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->nullable()->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->string('category', 20)->index();
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
