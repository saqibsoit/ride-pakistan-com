<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\SlotStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Faq;
use App\Models\Order;
use App\Models\Slot;
use App\Models\WaitlistEntry;
use App\Support\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/*
 * The booking engine's hot queries have an index to use (milestone 11).
 * EXPLAIN on MariaDB: every table in the plan must list a usable index
 * (possible_keys). The chosen plan isn't asserted, because with the few
 * rows of a test database the optimizer may well prefer a scan; the
 * plans on realistic data are recorded in docs/PERFORMANCE.md.
 */

/**
 * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
 * @return list<array<string, mixed>>
 */
function explainRows(Builder $query): array
{
    return array_map(fn (object $row): array => (array) $row, DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()));
}

/** @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query */
function expectIndexed(Builder $query, string ...$tables): void
{
    $rows = collect(explainRows($query))->filter(fn (array $row): bool => in_array($row['table'], $tables, true));

    expect($rows->pluck('table')->unique()->sort()->values()->all())->toBe(collect($tables)->unique()->sort()->values()->all());

    foreach ($rows as $row) {
        expect($row['possible_keys'])->not->toBeNull('No usable index on '.$row['table'].' for: '.$query->toSql());
    }
}

beforeEach(function (): void {
    if (DB::getDriverName() !== 'mariadb' && DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('EXPLAIN checks run on MariaDB/MySQL.');
    }
});

it('locks a slot and reads its seats with indexes (SlotLock)', function (): void {
    $slotId = Slot::factory()->create()->id;

    expectIndexed(Booking::query()->where('slot_id', $slotId)->whereIn('status', BookingStatus::seatOccupyingValues()), 'bookings');
    expectIndexed(BookingHold::query()->where('slot_id', $slotId)->where('expires_at', '>', now()), 'booking_holds');
    expectIndexed(WaitlistEntry::query()->where('slot_id', $slotId)->where('user_id', 1)->active(), 'waitlist_entries');
});

it('lists bookable slots with seat counts using indexes (Availability)', function (): void {
    $from = CarbonImmutable::now();

    expectIndexed(
        Slot::query()->withSeatCounts()->whereIn('slots.status', [SlotStatus::Open, SlotStatus::Closed])->where('slots.starts_at', '>=', $from)->where('slots.starts_at', '<', $from->addWeeks(4)),
        'slots', 'bookings', 'booking_holds',
    );

    expect(app(Availability::class)->upcoming())->toBeIterable();
});

it('finds usable credit packs and expired holds with indexes', function (): void {
    $slot = Slot::factory()->create();

    expectIndexed(CreditPack::query()->where('user_id', 1)->usableFor($slot), 'credit_packs');
    expectIndexed(Order::query()->where('status', OrderStatus::PendingPayment)->where('expires_at', '<=', now()), 'orders');
    expectIndexed(BookingHold::query()->where('expires_at', '<=', now()), 'booking_holds');
});

it('finds a page’s FAQs with an index', function (): void {
    expectIndexed(Faq::query()->published()->where('page_id', 1)->ordered(), 'faqs');
    expectIndexed(Faq::query()->published()->where('product_id', 1)->ordered(), 'faqs');
});
