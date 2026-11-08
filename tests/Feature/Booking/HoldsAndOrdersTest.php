<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\HoldSeats;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\Settings\UpdateSettings;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{0: User, 1: list<int>}
 */
function customerWithRiders(int $count = 1): array
{
    $user = User::factory()->create();
    $ids = Rider::factory()->count($count)->for($user)->create()->modelKeys();

    return [$user, array_values(array_map('intval', $ids))];
}

function rejectionReason(callable $callback): ?string
{
    try {
        $callback();
    } catch (BookingRejected $e) {
        return $e->reason;
    }

    return null;
}

/** Fill $seats of a slot with confirmed bookings for other customers. */
function fillSeats(Slot $slot, int $seats): void
{
    Booking::factory()->count($seats)->create(['slot_id' => $slot->id]);
}

it('creates a single-session order priced per rider and holds one seat each', function (): void {
    [$user, $riders] = customerWithRiders(3);
    $product = Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = Slot::factory()->create(['capacity' => 6]);

    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, $riders);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->amount_minor)->toBe(3_000_000)
        ->and($order->reference)->toStartWith('RP-')
        ->and($order->items()->count())->toBe(3)
        ->and((int) $order->items()->sum('unit_price_minor'))->toBe(3_000_000)
        ->and($order->expires_at?->diffInMinutes(now(), true))->toBeGreaterThan(29.9)->toBeLessThanOrEqual(30.0)
        ->and(BookingHold::query()->sole()->seats)->toBe(3)
        ->and($slot->fresh()?->seatsFree())->toBe(3)
        ->and(Activity::query()->where('log_name', 'orders')->where('event', 'created')->exists())->toBeTrue();
});

it('ignores any price the browser might send: only ids reach the action', function (): void {
    [$user, $riders] = customerWithRiders();
    $product = Product::factory()->create(['price_minor' => 1_000_000]);
    $slot = Slot::factory()->create();

    // Even a tampered in-memory model doesn't change what is charged:
    // the action re-reads the product row.
    $product->price_minor = 1;
    $product->type = ProductType::Package;

    $order = app(CreateOrder::class)->handle($user, $product, $slot->id, $riders);

    expect($order->amount_minor)->toBe(1_000_000)
        ->and($order->items()->sole()->unit_price_minor)->toBe(1_000_000)
        ->and($product->fresh()?->price_minor)->toBe(1_000_000);
});

it('holds the whole slot for a private session and blocks everyone else', function (): void {
    [$user, $riders] = customerWithRiders(2);
    $private = Product::factory()->private(1_500_000)->create();
    $slot = Slot::factory()->create(['capacity' => 6]);

    $order = app(CreateOrder::class)->handle($user, $private, $slot->id, $riders);

    expect($order->amount_minor)->toBe(3_000_000)
        ->and(BookingHold::query()->sole()->seats)->toBe(6)
        ->and(BookingHold::query()->sole()->is_private)->toBeTrue()
        ->and(Slot::query()->withSeatCounts()->findOrFail($slot->id)->seatsFree())->toBe(0);

    [$other, $otherRiders] = customerWithRiders();
    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($other, Product::factory()->create(), $slot->id, $otherRiders)))
        ->toBe(BookingRejected::SLOT_FULL);
});

it('refuses a private session on a slot with any booking or hold', function (string $occupant): void {
    $slot = Slot::factory()->create(['capacity' => 6]);

    match ($occupant) {
        'booking' => fillSeats($slot, 1),
        'hold' => BookingHold::factory()->create(['slot_id' => $slot->id, 'seats' => 1]),
    };

    [$user, $riders] = customerWithRiders();

    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, Product::factory()->private()->create(), $slot->id, $riders)))
        ->toBe(BookingRejected::SLOT_NOT_EMPTY);
})->with(['booking', 'hold']);

it('lets a private session take a slot whose only bookings were cancelled or holds expired', function (): void {
    $slot = Slot::factory()->create();
    Booking::factory()->status(BookingStatus::Cancelled)->create(['slot_id' => $slot->id]);
    BookingHold::factory()->expired()->create(['slot_id' => $slot->id]);
    [$user, $riders] = customerWithRiders();

    $order = app(CreateOrder::class)->handle($user, Product::factory()->private()->create(), $slot->id, $riders);

    expect($order->holds()->sole()->is_private)->toBeTrue();
});

it('keeps a paid private session blocking the slot with fewer riders than capacity', function (): void {
    $slot = Slot::factory()->create(['capacity' => 6]);
    Booking::factory()->create(['slot_id' => $slot->id, 'is_private' => true]);

    expect(Slot::query()->withSeatCounts()->findOrFail($slot->id)->seatsFree())->toBe(0)
        ->and($slot->fresh()?->seatsFree())->toBe(0);

    [$user, $riders] = customerWithRiders();
    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders)))
        ->toBe(BookingRejected::SLOT_FULL);
});

it('never goes over capacity', function (): void {
    $slot = Slot::factory()->create(['capacity' => 6]);
    fillSeats($slot, 4);
    [$user, $riders] = customerWithRiders(3);

    $e = null;

    try {
        app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);
    } catch (BookingRejected $e) {
    }

    expect($e?->reason)->toBe(BookingRejected::SLOT_FULL)
        ->and($e?->getMessage())->toBe('Only 2 seats are left in that slot.')
        ->and(Order::query()->count())->toBe(0)
        ->and(BookingHold::query()->count())->toBe(0);
});

it('counts only unexpired holds against capacity', function (): void {
    $slot = Slot::factory()->create(['capacity' => 2]);
    BookingHold::factory()->expired()->create(['slot_id' => $slot->id, 'seats' => 2]);
    [$user, $riders] = customerWithRiders(2);

    app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);

    expect($slot->fresh()?->seatsFree())->toBe(0);
});

it('refuses closed, cancelled, started and too-soon slots', function (string $case): void {
    $slot = match ($case) {
        'closed' => Slot::factory()->status(SlotStatus::Closed)->create(),
        'cancelled' => Slot::factory()->status(SlotStatus::Cancelled)->create(),
        'started' => Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->subMinutes(5))->create(),
        'notice' => Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->addHours(3))->create(),
    };

    if ($case === 'notice') {
        app(UpdateSettings::class)->handle([SettingKey::MinNoticeHours->value => 12]);
    }

    [$user, $riders] = customerWithRiders();

    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders)))
        ->toBe(BookingRejected::SLOT_NOT_BOOKABLE);
})->with(['closed', 'cancelled', 'started', 'notice']);

it('books manual slots like rule slots', function (): void {
    $slot = Slot::factory()->manual()->create(['capacity' => 2]);
    [$user, $riders] = customerWithRiders(2);

    app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);

    expect($slot->fresh()?->seatsFree())->toBe(0);
});

it('never seats the same rider twice in a slot', function (): void {
    [$user, $riders] = customerWithRiders();
    $slot = Slot::factory()->create();
    Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $riders[0]]);

    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders)))
        ->toBe(BookingRejected::RIDER_ALREADY_BOOKED);
});

it('refuses another customer’s riders', function (): void {
    [$user] = customerWithRiders();
    [, $strangers] = customerWithRiders();

    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, Product::factory()->create(), Slot::factory()->create()->id, $strangers)))
        ->toBe(BookingRejected::INVALID_RIDERS);
});

it('refuses inactive products', function (): void {
    [$user, $riders] = customerWithRiders();

    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, Product::factory()->inactive()->create(), Slot::factory()->create()->id, $riders)))
        ->toBe(BookingRejected::INVALID_PRODUCT);
});

it('returns the same held order when the same booking is submitted twice', function (): void {
    [$user, $riders] = customerWithRiders(2);
    $product = Product::factory()->create();
    $slot = Slot::factory()->create();

    $first = app(CreateOrder::class)->handle($user, $product, $slot->id, $riders);
    $second = app(CreateOrder::class)->handle($user, $product, $slot->id, array_reverse($riders));

    expect($second->is($first))->toBeTrue()
        ->and(Order::query()->count())->toBe(1)
        ->and(BookingHold::query()->count())->toBe(1);
});

it('sells a package without a date, priced once', function (): void {
    [$user] = customerWithRiders();
    $package = Product::factory()->package(8, 6_000_000)->create();

    $order = app(CreateOrder::class)->handle($user, $package, null, []);

    expect($order->amount_minor)->toBe(6_000_000)
        ->and($order->items()->count())->toBe(1)
        ->and(BookingHold::query()->count())->toBe(0);
});

it('sells a package with a first session for several riders, still priced once', function (): void {
    [$user, $riders] = customerWithRiders(2);
    $package = Product::factory()->package(8, 6_000_000)->create();
    $slot = nearSlot();

    $order = app(CreateOrder::class)->handle($user, $package, $slot->id, $riders);

    expect($order->amount_minor)->toBe(6_000_000)
        ->and((int) $order->items()->whereNotNull('slot_id')->sum('unit_price_minor'))->toBe(0)
        ->and(BookingHold::query()->sole()->seats)->toBe(2);
});

it('refuses a first package session after the package would expire', function (): void {
    [$user, $riders] = customerWithRiders();
    $package = Product::factory()->package(8, 6_000_000, 42)->create();
    $slot = Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->addDays(50)->setTime(8, 0))->create();

    expect(rejectionReason(fn () => app(CreateOrder::class)->handle($user, $package, $slot->id, $riders)))
        ->toBe(BookingRejected::NO_CREDITS);
});

it('lets HoldSeats refuse an empty rider list', function (): void {
    $order = Order::factory()->create();

    expect(rejectionReason(fn () => app(HoldSeats::class)->handle($order, Slot::factory()->create()->id, [], false, CarbonImmutable::now()->addHour())))
        ->toBe(BookingRejected::INVALID_RIDERS);
});

it('expires unpaid card orders when the hold runs out and gives the seats back', function (): void {
    [$user, $riders] = customerWithRiders(2);
    $slot = Slot::factory()->create(['capacity' => 2]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, $riders);

    expect($slot->fresh()?->seatsFree())->toBe(0)
        ->and(app(ReleaseExpiredHolds::class)->handle())->toBe(0);

    $this->travel(31)->minutes();

    // Seats count as free the moment the hold expires, even before the job runs.
    expect($slot->fresh()?->seatsFree())->toBe(2);

    $this->artisan('holds:release')->expectsOutput('Expired 1 order.')->assertSuccessful();

    expect($order->fresh()?->status)->toBe(OrderStatus::Expired)
        ->and(BookingHold::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'status_changed')->latest('id')->first()?->properties?->get('reason'))->toBe('Seat hold expired before payment');
});

it('leaves paid and transfer orders alone when releasing holds', function (): void {
    $paid = Order::factory()->status(OrderStatus::Paid)->create(['expires_at' => now()->subHour()]);
    $transfer = Order::factory()->status(OrderStatus::AwaitingTransfer)->create(['expires_at' => now()->subHour()]);
    BookingHold::factory()->expired()->create(['order_id' => $transfer->id]);

    app(ReleaseExpiredHolds::class)->handle();

    expect($paid->fresh()?->status)->toBe(OrderStatus::Paid)
        ->and($transfer->fresh()?->status)->toBe(OrderStatus::AwaitingTransfer)
        ->and(BookingHold::query()->count())->toBe(1);
});
