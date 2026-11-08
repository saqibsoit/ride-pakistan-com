<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Concrete sessions generated from schedule rules (GenerateSlots).
     * Times are stored in UTC; one slot per start time.
     */
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('schedule_rule_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->dateTime('starts_at')->unique();
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->string('status', 20)->default('open');
            $table->string('cancel_reason', 500)->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
