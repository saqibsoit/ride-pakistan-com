<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Slots\ChangeSlotStatus;
use App\Actions\Slots\CreateManualSlot;
use App\Actions\Slots\UpdateManualSlot;
use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\SlotNotUpdated;
use App\Filament\Resources\Slots\Pages\ListSlots;
use App\Models\Booking;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Support\Availability;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->seed();
    // Wednesday 11 Nov 2026, 09:00 Pakistan time.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = User::factory()->admin()->withMfa()->create();
});

function slotLocal(string $value): CarbonImmutable
{
    return CarbonImmutable::parse($value, 'Asia/Karachi');
}

it('adds a weekday one-off that shows on the schedule and can be booked', function (): void {
    $slot = app(CreateManualSlot::class)->handle(slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 4, $this->admin);

    $this->get('/schedule?week=2026-11-16')->assertOk()->assertSee('4:00 pm');

    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $order = app(CreateOrder::class)->handle($user, Product::query()->where('slug', 'single-session')->firstOrFail(), $slot->id, [$rider->id]);

    expect($order->items()->first()->slot_id)->toBe($slot->id)
        ->and(slotLocal('2026-11-18 16:00')->isWednesday())->toBeTrue();
});

it('adds a private-only slot from the panel that only private sessions can book', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(ListSlots::class)
        ->callAction('createManual', ['date' => '2026-11-19', 'start_time' => '10:00', 'end_time' => '12:00', 'capacity' => 4, 'private_only' => true])
        ->assertHasNoActionErrors();

    $slot = Slot::query()->where('private_only', true)->sole();
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();

    $group = Product::query()->where('slug', 'single-session')->firstOrFail();
    $private = Product::factory()->private()->create();

    expect(fn () => app(CreateOrder::class)->handle($user, $group, $slot->id, [$rider->id]))
        ->toThrow(BookingRejected::class, 'private bookings only');

    $order = app(CreateOrder::class)->handle($user, $private, $slot->id, [$rider->id]);
    expect($order->items()->first()->slot_id)->toBe($slot->id);

    $this->get('/schedule?week=2026-11-16')->assertSee('Private only');
});

it('keeps private-only slots out of group availability', function (): void {
    $slot = app(CreateManualSlot::class)->handle(slotLocal('2026-11-19 10:00'), slotLocal('2026-11-19 12:00'), 4, $this->admin, privateOnly: true);
    $availability = app(Availability::class);

    expect($availability->upcoming(30, Availability::TYPE_GROUP)->modelKeys())->not->toContain($slot->id)
        ->and($availability->upcoming(30, Availability::TYPE_PRIVATE)->modelKeys())->toContain($slot->id);
});

it('edits an empty manual slot: time, capacity and private-only', function (): void {
    $slot = app(CreateManualSlot::class)->handle(slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 4, $this->admin);

    app(UpdateManualSlot::class)->handle($slot, slotLocal('2026-11-19 09:00'), slotLocal('2026-11-19 11:00'), 6, true, $this->admin);

    $slot->refresh();
    expect($slot->label())->toBe('Thu 19 Nov 2026, 09:00–11:00')
        ->and($slot->capacity)->toBe(6)
        ->and($slot->private_only)->toBeTrue()
        ->and(Activity::query()->where('event', 'updated_manual')->where('causer_id', $this->admin->id)->exists())->toBeTrue();
});

it('never moves a booked slot or shrinks it below the seats taken', function (): void {
    $slot = app(CreateManualSlot::class)->handle(slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 4, $this->admin);
    Booking::factory()->count(2)->status(BookingStatus::Confirmed)->create(['slot_id' => $slot->id]);

    $update = app(UpdateManualSlot::class);

    expect(fn () => $update->handle($slot, slotLocal('2026-11-18 17:00'), slotLocal('2026-11-18 19:00'), 4, false, $this->admin))
        ->toThrow(SlotNotUpdated::class, 'time')
        ->and(fn () => $update->handle($slot, slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 1, false, $this->admin))
        ->toThrow(SlotNotUpdated::class, '2 seats')
        ->and(fn () => $update->handle($slot, slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 4, true, $this->admin))
        ->toThrow(SlotNotUpdated::class, 'private-only');

    // Capacity can still go up, or down to exactly the seats taken.
    $update->handle($slot, slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 2, false, $this->admin);
    expect($slot->refresh()->capacity)->toBe(2);
});

it('only edits open, future, manual slots', function (): void {
    $rule = Slot::factory()->at(slotLocal('2026-11-21 11:30'))->create();
    $closed = app(CreateManualSlot::class)->handle(slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 4, $this->admin);
    app(ChangeSlotStatus::class)->handle($closed, SlotStatus::Closed);

    $update = app(UpdateManualSlot::class);

    expect(fn () => $update->handle($rule, $rule->starts_at, $rule->ends_at, 3, false))->toThrow(SlotNotUpdated::class, 'manual')
        ->and(fn () => $update->handle($closed->refresh(), $closed->starts_at, $closed->ends_at, 3, false))->toThrow(SlotNotUpdated::class, 'open');
});

it('edits from the panel for admins only and clears the page cache', function (): void {
    $slot = app(CreateManualSlot::class)->handle(slotLocal('2026-11-18 16:00'), slotLocal('2026-11-18 18:00'), 4, $this->admin);

    // A cached schedule page must not survive the change.
    $this->get('/schedule?week=2026-11-16')->assertSee('4 spots left')->assertDontSee('5 spots left');

    $this->actingAs($this->admin);
    Livewire::test(ListSlots::class)
        ->callAction(TestAction::make('editManual')->table($slot), ['date' => '2026-11-18', 'start_time' => '16:00', 'end_time' => '18:00', 'capacity' => 5, 'private_only' => false])
        ->assertHasNoActionErrors();

    expect($slot->refresh()->capacity)->toBe(5);
    $this->get('/schedule?week=2026-11-16')->assertSee('5 spots left');

    $this->actingAs(User::factory()->staff()->withMfa()->create());
    Livewire::test(ListSlots::class)->assertActionHidden(TestAction::make('editManual')->table($slot));
});
