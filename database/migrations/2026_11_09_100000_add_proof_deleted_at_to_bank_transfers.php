<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer screenshots are deleted after review plus the retention period
 * (privacy.transfer_proof_retention_days); the record stays for accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table): void {
            $table->string('screenshot_path')->nullable()->change();
            $table->dateTime('proof_deleted_at')->nullable()->after('screenshot_path');
            $table->index(['status', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_transfers', function (Blueprint $table): void {
            $table->dropIndex(['status', 'verified_at']);
            $table->dropColumn('proof_deleted_at');
        });
    }
};
