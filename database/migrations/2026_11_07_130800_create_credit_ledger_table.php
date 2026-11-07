<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of every credit movement. The sum of delta for a
     * pack equals credits_total - credits_used.
     */
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->smallInteger('delta');
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['credit_pack_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
