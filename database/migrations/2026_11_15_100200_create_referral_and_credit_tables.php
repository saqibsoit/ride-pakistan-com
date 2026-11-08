<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals and PKR account credit (milestone 10e).
 *
 * users.referral_code: the customer's personal link (/r/{code}), created
 * on first use. referrals: one per referred customer, with the email and
 * phone they signed up with (abuse checks). account_credit_ledger: signed
 * PKR entries; the balance is their sum. order_discounts: the discount
 * lines of an order, one per kind, written at checkout from Part B on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('referral_code', 12)->nullable()->unique()->after('phone');
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('referred_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('signup_email', 190)->nullable();
            $table->string('signup_phone', 20)->nullable();
            $table->foreignId('rewarded_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->dateTime('rewarded_at')->nullable();
            $table->timestamps();

            $table->index('referrer_id');
        });

        Schema::create('account_credit_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->bigInteger('delta_minor');
            $table->string('reason', 30)->index();
            $table->foreignId('referral_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('order_discounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->nullableMorphs('source');
            $table->string('label', 120);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->unique(['order_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('account_credit_ledger');
        Schema::dropIfExists('referrals');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
