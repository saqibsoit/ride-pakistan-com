<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Package credits, granted only when the order is paid. Validity runs
     * from confirmation (PLAN §2). One pack per order.
     */
    public function up(): void
    {
        Schema::create('credit_packs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('credits_total');
            $table->unsignedSmallInteger('credits_used')->default(0);
            $table->dateTime('valid_from');
            $table->dateTime('expires_at');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packs');
    }
};
