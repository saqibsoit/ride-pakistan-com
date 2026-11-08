<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts at checkout (milestone 10e Part B): orders keep the price
 * before discounts in subtotal_minor; amount_minor becomes what the
 * customer pays (card or transfer) after promo, referral, gift voucher
 * and account credit. Orders placed so far had no discounts, so their
 * subtotal is their amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('subtotal_minor')->default(0)->after('payment_method');
        });

        DB::table('orders')->update(['subtotal_minor' => DB::raw('amount_minor')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('subtotal_minor');
        });
    }
};
