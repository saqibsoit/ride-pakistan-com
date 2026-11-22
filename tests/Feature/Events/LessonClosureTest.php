<?php

declare(strict_types=1);

use App\Actions\Events\ApplyLessonClosure;
use App\Actions\Events\CancelEventOccurrence;
use App\Actions\Events\ChangeRideEventStatus;
use App\Actions\Events\CreateEventOrder;
use App\Actions\Events\DuplicateRideEvent;
use App\Actions\Events\SaveEventOccurrence;
use App\Actions\Slots\GenerateSlots;
use App\Actions\Slots\SlotOverlap;
use App\Enums\BookingStatus;
use App\Enums\LessonClosure;
use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Booking;
use App\Models\RideEvent;
use App\Models\RideEventTier;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\LessonBookingsToMove;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/** A lesson slot $days ahead at $hour (2 hours), Pakistan time. */
function lessonAt(int $days, int $hour): Slot
{
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays($days)->setTime($hour, 0);

    return Slot::factory()->at($local)->create();
}

function campDate(RideEvent $event, int $days, LessonClosure $closure): Slot
{
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays($days)->setTime(10, 0);

    return app(SaveEventOccurrence::class)->create($event, $local, $local->setTime(17, 0), 10, $closure, User::factory()->admin()->create());
}

beforeEach(function (): void {
    Notification::fake();
});

it('closes every lesson on the day, keeps their bookings and lists them for an admin', function (): void {
    $morning = lessonAt(10, 8);   // 08:00–10:00: ends as the camp starts
    $afternoon = lessonAt(10, 15); // 15:00–17:00: inside the camp
    $nextDay = lessonAt(11, 8);
    $booking = Booking::factory()->create(['slot_id' => $morning->id]);
    $admin = User::factory()->admin()->create();
    $event = RideEvent::factory()->published()->create();

    $date = campDate($event, 10, LessonClosure::Day);

    expect($morning->refresh()->status)->toBe(SlotStatus::Closed)
        ->and($morning->closed_for_slot_id)->toBe($date->id)
        ->and($afternoon->refresh()->status)->toBe(SlotStatus::Closed)
        ->and($nextDay->refresh()->status)->toBe(SlotStatus::Open)
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(ApplyLessonClosure::clashesFor($date)->modelKeys())->toBe([$booking->id]);

    Notification::assertSentTo($admin, LessonBookingsToMove::class);
});

it('closes only overlapping lessons, with the slot buffer, in overlap mode', function (): void {
    $morning = lessonAt(10, 7);   // 07:00–09:00: an hour before the camp
    $close = lessonAt(10, 8);     // 08:00–10:00: within the 30-minute buffer
    $afternoon = lessonAt(10, 15);
    $event = RideEvent::factory()->published()->create();

    campDate($event, 10, LessonClosure::Overlap);

    expect($morning->refresh()->status)->toBe(SlotStatus::Open)
        ->and($close->refresh()->status)->toBe(SlotStatus::Closed)
        ->and($afternoon->refresh()->status)->toBe(SlotStatus::Closed);
});

it('closes nothing for a draft event, and closes when it is published', function (): void {
    $lesson = lessonAt(10, 15);
    $event = RideEvent::factory()->create();
    campDate($event, 10, LessonClosure::Day);

    expect($lesson->refresh()->status)->toBe(SlotStatus::Open);

    app(ChangeRideEventStatus::class)->handle($event, RideEventStatus::Published, User::factory()->admin()->create());

    expect($lesson->refresh()->status)->toBe(SlotStatus::Closed);

    app(ChangeRideEventStatus::class)->handle($event, RideEventStatus::Draft, User::factory()->admin()->create());

    expect($lesson->refresh()->status)->toBe(SlotStatus::Open)
        ->and($lesson->closed_for_slot_id)->toBeNull();
});

it('reopens the lessons it closed when the date is cancelled, but not ones closed by hand', function (): void {
    $byEvent = lessonAt(10, 15);
    $byHand = lessonAt(10, 8);
    Slot::query()->whereKey($byHand->id)->update(['status' => SlotStatus::Closed->value]);
    $event = RideEvent::factory()->published()->create();
    $date = campDate($event, 10, LessonClosure::Day);

    app(CancelEventOccurrence::class)->handle($date, SlotStatus::Cancelled, User::factory()->admin()->create(), 'Not enough interest');

    expect($byEvent->refresh()->status)->toBe(SlotStatus::Open)
        ->and($byHand->refresh()->status)->toBe(SlotStatus::Closed);
});

it('creates new rule slots on a closed day as closed, and refuses private requests that day', function (): void {
    $event = RideEvent::factory()->published()->create();
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(10)->setTime(10, 0);
    $date = campDate($event, 10, LessonClosure::Day);

    ScheduleRule::factory()->create(['day_of_week' => $local->dayOfWeekIso, 'start_time' => '15:00', 'end_time' => '17:00', 'capacity' => 6]);
    app(GenerateSlots::class)->handle(3);

    $generated = Slot::query()->lessons()->where('starts_at', $local->setTime(15, 0)->utc())->firstOrFail();

    expect($generated->status)->toBe(SlotStatus::Closed)
        ->and($generated->closed_for_slot_id)->toBe($date->id)
        ->and(app(SlotOverlap::class)->firstClash($local->setTime(7, 0), $local->setTime(9, 0))?->id)->toBe($date->id)
        ->and(app(SlotOverlap::class)->firstClash($local->addDay()->setTime(7, 0), $local->addDay()->setTime(9, 0)))->toBeNull();
});

it('never lets an event date block lessons when it keeps them open', function (): void {
    $lesson = lessonAt(10, 15);
    $event = RideEvent::factory()->published()->create();
    $local = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(10)->setTime(10, 0);
    campDate($event, 10, LessonClosure::None);

    expect($lesson->refresh()->status)->toBe(SlotStatus::Open)
        ->and(app(SlotOverlap::class)->firstClash($local->setTime(12, 0), $local->setTime(13, 0)))->toBeNull();
});

it('keeps an event date’s times while people have booked it, and capacity above the seats taken', function (): void {
    $event = RideEvent::factory()->published()->create();
    $date = campDate($event, 10, LessonClosure::None);
    app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, eventPeople($event, 3));

    expect(fn () => app(SaveEventOccurrence::class)->update($date, $date->starts_at->addHour(), $date->ends_at, 10, LessonClosure::None))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(SaveEventOccurrence::class)->update($date, $date->starts_at, $date->ends_at, 2, LessonClosure::None))
        ->toThrow(InvalidArgumentException::class);

    $saved = app(SaveEventOccurrence::class)->update($date, $date->starts_at, $date->ends_at, 12, LessonClosure::Day);

    expect($saved->capacity)->toBe(12)->and($saved->lesson_closure)->toBe(LessonClosure::Day);
});

it('needs a price and a date to come before publishing', function (): void {
    $event = RideEvent::factory()->create();
    $admin = User::factory()->admin()->create();

    expect(fn () => app(ChangeRideEventStatus::class)->handle($event, RideEventStatus::Published, $admin))->toThrow(InvalidStatusTransition::class);

    campDate($event, 10, LessonClosure::None);
    $event->tiers()->delete();

    expect(fn () => app(ChangeRideEventStatus::class)->handle($event, RideEventStatus::Published, $admin))->toThrow(InvalidStatusTransition::class);
});

it('duplicates an event with its content, tiers and questions, as a draft without dates', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create([
        'slug' => 'cowboy-camp',
        'blocks' => [['type' => 'programme', 'data' => ['items' => [['time' => '10:00', 'activity' => 'Arrival']]]]],
        'questions' => [['key' => 'diet', 'label' => 'Dietary needs', 'type' => 'text', 'required' => false, 'scope' => 'attendee']],
        'acknowledgement_text' => 'No hat, no ride.',
        'is_featured' => true,
    ]);
    RideEventTier::factory()->for($event, 'event')->create(['name' => 'Child', 'price_minor' => 500_000, 'seats_per_occurrence' => 4]);
    campDate($event, 10, LessonClosure::Day);
    $admin = User::factory()->admin()->create();

    $copy = app(DuplicateRideEvent::class)->handle($event, $admin);
    $second = app(DuplicateRideEvent::class)->handle($event, $admin);

    expect($copy->refresh()->status)->toBe(RideEventStatus::Draft)
        ->and($copy->slug)->toBe('cowboy-camp-copy')
        ->and($second->slug)->toBe('cowboy-camp-copy-2')
        ->and($copy->is_featured)->toBeFalse()
        ->and($copy->deposit_percent)->toBe(50)
        ->and($copy->blocks)->toBe($event->blocks)
        ->and($copy->questions)->toBe($event->questions)
        ->and($copy->acknowledgement_text)->toBe('No hat, no ride.')
        ->and($copy->tiers()->pluck('name')->all())->toBe(['Per person', 'Child'])
        ->and($copy->tiers()->where('name', 'Child')->value('seats_per_occurrence'))->toBe(4)
        ->and($copy->occurrences()->count())->toBe(0);
});
