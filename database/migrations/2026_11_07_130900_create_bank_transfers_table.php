<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bank transfer submissions awaiting manual verification (PLAN §3.2).
     * screenshot_path points at the private disk and is never public.
     */
    public function up(): void
    {
        Schema::create('bank_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('screenshot_path');
            $table->string('transaction_ref', 100)->nullable();
            $table->dateTime('submitted_at');
            $table->string('status', 20)->default('submitted');
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transfers');
    }
};
