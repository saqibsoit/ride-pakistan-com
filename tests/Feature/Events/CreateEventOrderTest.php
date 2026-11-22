<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Events\CreateEventOrder;
use App\Enums\BalanceStatus;
use App\Enums\EventQuestionScope;
use App\Enums\EventQuestionType;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\RideEvent;
use App\Models\RideEventTier;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Support\Availability;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** An occurrence of $event $days ahead, 10:00–17:00 Pakistan time. */
function eventDate(RideEvent $event, int $days = 20, int $capacity = 10, int $hour = 10): Slot
{
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays($days)->setTime($hour, 0);

    return Slot::factory()->forEvent($event)->at($local, 7)->create(['capacity' => $capacity]);
}

/**
 * @return list<array{tier_id: int, name: string, answers?: array<string, mixed>}>
 */
function eventPeople(RideEvent $event, int $count, ?RideEventTier $tier = null, string $prefix = 'Guest'): array
{
    $tier ??= $event->tiers()->firstOrFail();

    return array_map(fn (int $i): array => ['tier_id' => $tier->id, 'name' => $prefix.' '.$i], range(1, $count));
}

function eventRejection(callable $callback): ?string
{
    try {
        $callback();
    } catch (BookingRejected $e) {
        return $e->reason;
    }

    return null;
}

it('creates an event order priced from the tiers and holds one seat per person', function (): void {
    $event = RideEvent::factory()->published()->create();
    $date = eventDate($event);
    $user = User::factory()->create();

    $order = app(CreateEventOrder::class)->handle($user, $event, $date->id, eventPeople($event, 3));

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->ride_event_id)->toBe($event->id)
        ->and($order->product_id)->toBeNull()
        ->and($order->product_type)->toBe(ProductType::Event)
        ->and($order->product_name)->toBe($event->title)
        ->and($order->subtotal_minor)->toBe(3_000_000)
        ->and($order->amount_minor)->toBe(3_000_000)
        ->and($order->balance_minor)->toBe(0)
        ->and($order->items)->toHaveCount(3)
        ->and(BookingHold::query()->where('order_id', $order->id)->value('seats'))->toBe(3)
        ->and($user->riders()->pluck('name')->sort()->values()->all())->toBe(['Guest 1', 'Guest 2', 'Guest 3']);
});

it('charges a deposit now and keeps the rest as the balance, rounded up to whole rupees', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $event->tiers()->first()->update(['price_minor' => 1_000_100]); // PKR 10,001
    $date = eventDate($event);

    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 1));

    expect($order->deposit_percent)->toBe(50)
        ->and($order->amount_minor)->toBe(500_100) // PKR 5,001 (5,000.50 rounded up)
        ->and($order->balance_minor)->toBe(500_000)
        ->and($order->priceMinor())->toBe(1_000_100)
        ->and($order->discountMinor())->toBe(0);
});

it('opens the balance when the deposit is paid, due on the day', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create();
    $date = eventDate($event);
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 2));

    app(FulfilOrder::class)->handle($order);

    $balance = $order->refresh()->balance;

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($balance)->not->toBeNull()
        ->and($balance->amount_minor)->toBe(1_000_000)
        ->and($balance->status)->toBe(BalanceStatus::Due)
        ->and($balance->due_at->equalTo($date->starts_at))->toBeTrue()
        ->and(Booking::query()->where('order_id', $order->id)->whereNotNull('order_item_id')->count())->toBe(2);

    // Idempotent: a second fulfilment changes nothing.
    app(FulfilOrder::class)->handle($order);
    expect($order->balance()->count())->toBe(1);
});

it('never takes more people than the occurrence capacity', function (): void {
    $event = RideEvent::factory()->published()->create();
    $date = eventDate($event, capacity: 3);
    app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 2));

    $reason = eventRejection(fn () => app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 2)));

    expect($reason)->toBe(BookingRejected::SLOT_FULL)
        ->and((int) BookingHold::query()->where('slot_id', $date->id)->sum('seats'))->toBe(2)
        ->and(Order::query()->count())->toBe(1);
});

it('never takes more of a tier than its own seats, even with room in the occurrence', function (): void {
    $event = RideEvent::factory()->published()->create();
    $adult = $event->tiers()->firstOrFail();
    $child = RideEventTier::factory()->for($event, 'event')->create(['name' => 'Child', 'price_minor' => 600_000, 'seats_per_occurrence' => 2, 'sort' => 1]);
    $date = eventDate($event, capacity: 10);

    $first = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, [
        ['tier_id' => $adult->id, 'name' => 'Parent'],
        ['tier_id' => $child->id, 'name' => 'Kid'],
    ]);

    expect($first->subtotal_minor)->toBe(1_600_000);

    $reason = eventRejection(fn () => app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 2, $child, 'Kid')));

    expect($reason)->toBe(BookingRejected::TIER_FULL);

    // Paid bookings count towards the tier too.
    app(FulfilOrder::class)->handle($first);
    app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 1, $child, 'Kid'));

    expect(eventRejection(fn () => app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 1, $child, 'Other kid'))))
        ->toBe(BookingRejected::TIER_FULL)
        ->and(eventRejection(fn () => app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 3, $adult))))
        ->toBeNull();
});

it('checks the group size, the booking window, the event and its date', function (): void {
    $event = RideEvent::factory()->published()->create(['min_per_booking' => 2, 'max_per_booking' => 4, 'booking_closes_hours' => 24]);
    $date = eventDate($event);
    $soon = eventDate($event, days: 0, hour: 23);
    $other = eventDate(RideEvent::factory()->published()->create());
    $user = User::factory()->create();
    $create = fn (int $slot, int $people, ?RideEvent $for = null) => fn () => app(CreateEventOrder::class)->handle($user, $for ?? $event, $slot, eventPeople($event, $people));

    expect(eventRejection($create($date->id, 1)))->toBe(BookingRejected::EVENT_RULE)
        ->and(eventRejection($create($date->id, 5)))->toBe(BookingRejected::EVENT_RULE)
        ->and(eventRejection($create($soon->id, 2)))->toBe(BookingRejected::EVENT_RULE)
        ->and(eventRejection($create($other->id, 2)))->toBe(BookingRejected::SLOT_NOT_BOOKABLE);

    $event->update(['booking_opens_at' => now()->addDay()]);
    expect(eventRejection($create($date->id, 2)))->toBe(BookingRejected::EVENT_RULE);

    $draft = RideEvent::factory()->create();
    $draftDate = eventDate($draft);
    expect(eventRejection(fn () => app(CreateEventOrder::class)->handle($user, $draft, $draftDate->id, eventPeople($draft, 1))))->toBe(BookingRejected::EVENT_RULE);

    DB::table('slots')->where('id', $date->id)->update(['status' => SlotStatus::Closed->value]);
    $event->update(['booking_opens_at' => null]);
    expect(eventRejection($create($date->id, 2)))->toBe(BookingRejected::SLOT_NOT_BOOKABLE);
});

it('requires the checkbox, a name per person and the required answers, and stores answers encrypted', function (): void {
    $event = RideEvent::factory()->published()->create([
        'acknowledgement_text' => 'I’ll wear a cowboy hat and western wear.',
        'questions' => [
            ['key' => 'diet', 'label' => 'Dietary needs', 'type' => EventQuestionType::Text->value, 'required' => false, 'scope' => EventQuestionScope::Attendee->value],
            ['key' => 'size', 'label' => 'T-shirt size', 'type' => EventQuestionType::Select->value, 'options' => ['S', 'M', 'L'], 'required' => true, 'scope' => EventQuestionScope::Attendee->value],
            ['key' => 'first', 'label' => 'First time riding?', 'type' => EventQuestionType::YesNo->value, 'required' => true, 'scope' => EventQuestionScope::Booking->value],
        ],
    ]);
    $tier = $event->tiers()->firstOrFail();
    $date = eventDate($event);
    $user = User::factory()->create();
    $people = [
        ['tier_id' => $tier->id, 'name' => 'Ayesha', 'answers' => ['size' => 'M', 'diet' => 'Vegetarian', 'ignored' => 'x']],
        ['tier_id' => $tier->id, 'name' => 'Bilal', 'answers' => ['size' => 'L']],
    ];
    $create = fn (array $people, array $booking, bool $ticked) => fn () => app(CreateEventOrder::class)->handle($user, $event, $date->id, $people, $booking, $ticked);

    expect(eventRejection($create($people, ['first' => 'yes'], false)))->toBe(BookingRejected::EVENT_RULE)
        ->and(eventRejection($create($people, [], true)))->toBe(BookingRejected::EVENT_RULE)
        ->and(eventRejection($create([$people[0], ['tier_id' => $tier->id, 'name' => 'Bilal', 'answers' => ['size' => 'XXL']]], ['first' => 'no'], true)))->toBe(BookingRejected::EVENT_RULE)
        ->and(eventRejection($create([$people[0], [...$people[0], 'name' => ' ayesha ']], ['first' => 'no'], true)))->toBe(BookingRejected::EVENT_RULE);

    $order = app(CreateEventOrder::class)->handle($user, $event, $date->id, $people, ['first' => 'Yes'], true);

    $items = $order->items()->with('rider')->get()->keyBy(fn ($item) => $item->rider->name);

    expect($items['Ayesha']->answers)->toBe(['diet' => 'Vegetarian', 'size' => 'M'])
        ->and($items['Bilal']->answers)->toBe(['size' => 'L'])
        ->and($order->answers)->toBe(['first' => 'yes'])
        ->and($order->meta['acknowledgement']['text'])->toBe('I’ll wear a cowboy hat and western wear.')
        ->and(DB::table('order_items')->where('id', $items['Ayesha']->id)->value('answers'))->not->toContain('Vegetarian')
        ->and(DB::table('orders')->where('id', $order->id)->value('answers'))->not->toContain('yes');
});

it('reuses a signed-in customer’s rider with the same name', function (): void {
    $event = RideEvent::factory()->published()->create();
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create(['name' => 'Zara Khan']);

    $order = app(CreateEventOrder::class)->handle($user, $event, eventDate($event)->id, [
        ['tier_id' => $event->tiers()->firstOrFail()->id, 'name' => 'zara khan'],
    ]);

    expect($order->items()->value('rider_id'))->toBe($rider->id)
        ->and($user->riders()->count())->toBe(1);
});

it('keeps event dates out of lessons: lists, lesson orders, credits and moves', function (): void {
    $event = RideEvent::factory()->published()->create();
    $date = eventDate($event, days: 2);
    $lesson = nearSlot(2, 8);
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $product = Product::factory()->create();

    expect(app(Availability::class)->upcoming(20)->modelKeys())->toContain($lesson->id)->not->toContain($date->id)
        ->and(app(Availability::class)->find($date->id))->toBeNull()
        ->and(eventRejection(fn () => app(CreateOrder::class)->handle($user, $product, $date->id, [$rider->id])))->toBe(BookingRejected::SLOT_NOT_BOOKABLE);

    $pack = CreditPack::factory()->for($user)->create(['credits_total' => 4, 'expires_at' => now()->addMonths(2)]);
    expect(eventRejection(fn () => app(BookWithCredit::class)->handle($user, $date->id, [$rider->id], only: $pack)))->toBe(BookingRejected::SLOT_NOT_BOOKABLE);

    // An event order can't take a lesson slot either.
    expect(eventRejection(fn () => app(CreateEventOrder::class)->handle($user, $event, $lesson->id, eventPeople($event, 1))))->toBe(BookingRejected::SLOT_NOT_BOOKABLE);
});

it('lets an event date start at the same time as a lesson, but keeps lessons unique', function (): void {
    $lesson = nearSlot(5, 10);
    $event = RideEvent::factory()->published()->create();

    $date = Slot::factory()->forEvent($event)->create(['starts_at' => $lesson->starts_at, 'ends_at' => $lesson->starts_at->addHours(7)]);

    expect($date->exists)->toBeTrue()
        ->and(fn () => Slot::factory()->create(['starts_at' => $lesson->starts_at, 'ends_at' => $lesson->ends_at]))
        ->toThrow(UniqueConstraintViolationException::class);
});
