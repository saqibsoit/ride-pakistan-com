<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest checkout (decided 2026-11-12): orders, gift voucher purchases and
 * private session requests can be placed without signing in.
 *
 * - contact_name / contact_whatsapp: what the guest typed. An account that
 *   already used the email is never changed, so the details for this one
 *   booking live on the record itself.
 * - placed_as_guest: the record was placed signed out (no account-based
 *   discounts, guest screens show record-level data only).
 * - manage_link_version: part of every signed "Manage your booking" link;
 *   raising it (RevokeManageLinks) stops every link sent so far.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['orders', 'voucher_orders', 'private_session_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('contact_name', 120)->nullable();
                $table->string('contact_whatsapp', 32)->nullable();
                $table->boolean('placed_as_guest')->default(false);
                $table->unsignedInteger('manage_link_version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['contact_name', 'contact_whatsapp', 'placed_as_guest', 'manage_link_version']);
            });
        }
    }
};
