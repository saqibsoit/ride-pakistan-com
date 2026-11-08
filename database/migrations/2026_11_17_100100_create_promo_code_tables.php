<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes (milestone 10e): percentage or fixed PKR, limited to the
 * products in product_promo_code (none = every product), with a window,
 * usage limits, a minimum order and a first-booking-only option.
 * promo_redemptions records each use (one code per order); it is written
 * at checkout from Part B on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('description', 255)->nullable();
            $table->string('discount_type', 10);
            $table->unsignedTinyInteger('percent')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_customer')->nullable();
            $table->unsignedBigInteger('min_order_minor')->nullable();
            $table->boolean('first_booking_only')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('product_promo_code', function (Blueprint $table): void {
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->primary(['promo_code_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('promo_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->index(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('product_promo_code');
        Schema::dropIfExists('promo_codes');
    }
};
