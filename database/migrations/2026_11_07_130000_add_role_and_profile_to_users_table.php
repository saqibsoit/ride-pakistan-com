<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles (customer, staff, admin), contact and consent fields, and the
     * columns Filament's app (TOTP) multi-factor authentication needs.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('customer')->after('email')->index();
            $table->string('phone', 20)->nullable()->after('role')->index();
            $table->boolean('marketing_consent')->default(false)->after('phone');
            $table->dateTime('marketing_consent_at')->nullable()->after('marketing_consent');
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropIndex(['phone']);
            $table->dropColumn([
                'role',
                'phone',
                'marketing_consent',
                'marketing_consent_at',
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
