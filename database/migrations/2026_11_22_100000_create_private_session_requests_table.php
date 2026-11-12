<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 15B: a customer asks for a private session at their own date and
 * time. An admin approves (a private-only slot, a hold and an order the
 * customer pays within the window) or declines; unanswered requests
 * expire. Status changes only through PrivateRequestStateMachine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_session_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('riders');
            $table->json('rider_ids');
            $table->text('note')->nullable();
            $table->string('status', 20)->default('requested');
            $table->dateTime('answer_by');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('suggested_times')->nullable();
            $table->foreignId('slot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('pay_by')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'answer_by']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_session_requests');
    }
};
