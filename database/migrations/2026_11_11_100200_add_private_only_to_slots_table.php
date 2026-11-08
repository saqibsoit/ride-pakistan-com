<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private-only slots (milestone 10a): a manual slot that only private
 * sessions can book. Group sessions don't see or book it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->boolean('private_only')->default(false)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('slots', function (Blueprint $table): void {
            $table->dropColumn('private_only');
        });
    }
};
