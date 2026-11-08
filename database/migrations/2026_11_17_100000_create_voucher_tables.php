<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift vouchers (milestone 10e).
 *
 * voucher_orders: a purchase, paid by card or bank transfer like an order
 * but without seats. Its transfer proof lives on the private transfers
 * disk and is never public. vouchers: issued only once the purchase is
 * paid (or by an admin, with a reason). voucher_ledger: every balance
 * change, signed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name', 120)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('PKR');
            $table->string('status', 30)->default('pending_payment')->index();
            $table->string('payment_method', 20)->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->index();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_email', 190)->nullable();
            $table->text('message')->nullable();
            $table->date('send_on')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('paid_at')->nullable()->index();
            $table->string('screenshot_path')->nullable();
            $table->string('transaction_ref', 100)->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->dateTime('proof_deleted_at')->nullable();
            $table->boolean('needs_review')->default(false)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });

        Schema::create('vouchers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('voucher_order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('issue_reason', 500)->nullable();
            $table->string('label', 120)->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_email', 190)->nullable();
            $table->unsignedBigInteger('initial_minor');
            $table->unsignedBigInteger('balance_minor');
            $table->string('currency', 3)->default('PKR');
            $table->dateTime('expires_at')->index();
            $table->string('status', 20)->default('active')->index();
            $table->dateTime('delivered_at')->nullable()->index();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('voucher_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->bigInteger('delta_minor');
            $table->string('reason', 20)->index();
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_ledger');
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('voucher_orders');
    }
};
