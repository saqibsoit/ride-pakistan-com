<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds of bank-transfer orders are paid back by the team's own bank
 * transfer (milestone 10e): the date that transfer left the account
 * (Pakistan date), for reconciling with the bank statement. The outgoing
 * transfer's bank reference is the payment's provider_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->date('transferred_on')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('transferred_on');
        });
    }
};
