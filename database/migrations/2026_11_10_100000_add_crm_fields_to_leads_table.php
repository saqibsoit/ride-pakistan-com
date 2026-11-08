<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Milestone 9 CRM: Instagram handle for DM leads, pipeline fields
 * (lost reason, follow-up, booked order), first-touch time, consent at
 * capture and the event id shared by the browser Pixel and Meta CAPI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('instagram_handle', 30)->nullable()->after('email')->index();
            $table->string('lost_reason', 500)->nullable()->after('status');
            $table->date('next_follow_up_at')->nullable()->after('lost_reason')->index();
            $table->dateTime('first_touch_at')->nullable()->after('referrer');
            $table->boolean('consent_tracking')->default(false)->after('first_touch_at');
            $table->uuid('tracking_event_id')->nullable()->after('consent_tracking');
            $table->foreignId('booked_order_id')->nullable()->after('user_id')->index()->constrained('orders')->nullOnDelete();
            $table->dateTime('booked_at')->nullable()->after('booked_order_id');
            $table->foreignId('created_by')->nullable()->after('booked_at')->index()->constrained('users')->nullOnDelete();

            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('booked_order_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['email']);
            $table->dropIndex(['phone']);
            $table->dropIndex(['instagram_handle']);
            $table->dropIndex(['next_follow_up_at']);
            $table->dropColumn(['instagram_handle', 'lost_reason', 'next_follow_up_at', 'first_touch_at', 'consent_tracking', 'tracking_event_id', 'booked_at']);
        });
    }
};
