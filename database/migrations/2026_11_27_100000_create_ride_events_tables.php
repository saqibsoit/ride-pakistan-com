<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Events (decided 2026-11-22): camps, workshops, trips and rides built in
 * the admin panel. An event's occurrences are slots (ride_event_id set),
 * so seats, holds, orders and FulfilOrder are the lesson machinery.
 *
 * - Lesson slots stay unique on their start time; event occurrences may
 *   start when a lesson does (a stored generated column carries the
 *   unique index instead of starts_at).
 * - Orders may belong to an event instead of a product; deposit orders
 *   keep the rest as an order_balances row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_event_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 60);
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ride_events', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title', 120);
            $table->foreignId('ride_event_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('location_name', 120)->nullable();
            $table->string('cover_image')->nullable();
            $table->string('cover_image_alt', 200)->nullable();
            $table->json('gallery')->nullable();
            $table->string('video_url')->nullable();
            $table->json('blocks')->nullable();
            $table->json('questions')->nullable();
            $table->string('payment_mode', 20)->default('full');
            $table->unsignedTinyInteger('deposit_percent')->nullable();
            $table->unsignedTinyInteger('non_refundable_percent')->default(0);
            $table->unsignedSmallInteger('cancellation_cutoff_days')->default(7);
            $table->string('balance_due', 20)->default('on_the_day');
            $table->unsignedSmallInteger('balance_deadline_hours')->default(0);
            $table->unsignedSmallInteger('min_per_booking')->default(1);
            $table->unsignedSmallInteger('max_per_booking')->nullable();
            $table->dateTime('booking_opens_at')->nullable();
            $table->unsignedSmallInteger('booking_closes_hours')->default(24);
            $table->boolean('waitlist_enabled')->default(false);
            $table->string('acknowledgement_text', 300)->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->string('meta_title', 70)->nullable();
            $table->string('meta_description', 170)->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ride_event_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ride_event_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('seats_per_occurrence')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('slots', function (Blueprint $table): void {
            $table->foreignId('ride_event_id')->nullable()->after('schedule_rule_id')->constrained()->restrictOnDelete();
            $table->string('lesson_closure', 10)->default('none')->after('private_only');
            $table->foreignId('closed_for_slot_id')->nullable()->after('lesson_closure')->constrained('slots')->nullOnDelete();
        });

        Schema::table('slots', function (Blueprint $table): void {
            $table->dropUnique(['starts_at']);
            $table->index('starts_at');
        });

        // Only lesson slots are unique on their start (MariaDB has no partial indexes).
        DB::statement('ALTER TABLE slots ADD COLUMN lesson_starts_at DATETIME AS (IF(ride_event_id IS NULL, starts_at, NULL)) STORED');
        DB::statement('ALTER TABLE slots ADD UNIQUE INDEX slots_lesson_starts_at_unique (lesson_starts_at)');

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('ride_event_id')->nullable()->after('product_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('deposit_percent')->nullable()->after('amount_refunded_minor');
            $table->unsignedBigInteger('balance_minor')->default(0)->after('deposit_percent');
            $table->text('answers')->nullable()->after('meta');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->change();
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('ride_event_tier_id')->nullable()->after('rider_id')->constrained()->restrictOnDelete();
            $table->text('answers')->nullable()->after('unit_price_minor');
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });

        Schema::create('order_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->string('currency', 3)->default('PKR');
            $table->dateTime('due_at');
            $table->dateTime('online_until');
            $table->string('status', 20)->default('due')->index();
            $table->string('method', 20)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->unsignedInteger('stripe_attempts')->default(0);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('purpose', 10)->default('order')->after('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });

        Schema::dropIfExists('order_balances');

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_item_id');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ride_event_tier_id');
            $table->dropColumn('answers');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ride_event_id');
            $table->dropColumn(['deposit_percent', 'balance_minor', 'answers']);
        });

        DB::statement('ALTER TABLE slots DROP INDEX slots_lesson_starts_at_unique');
        DB::statement('ALTER TABLE slots DROP COLUMN lesson_starts_at');

        Schema::table('slots', function (Blueprint $table): void {
            $table->dropIndex(['starts_at']);
            $table->unique('starts_at');
            $table->dropConstrainedForeignId('closed_for_slot_id');
            $table->dropConstrainedForeignId('ride_event_id');
            $table->dropColumn('lesson_closure');
        });

        Schema::dropIfExists('ride_event_tiers');
        Schema::dropIfExists('ride_events');
        Schema::dropIfExists('ride_event_categories');
    }
};
