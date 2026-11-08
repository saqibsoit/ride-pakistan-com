<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Settings\UpdateSettings;
use App\Actions\Slots\ChangeSlotStatus;
use App\Actions\Waitlist\JoinWaitlist;
use App\Actions\Waitlist\LeaveWaitlist;
use App\Actions\Waitlist\OfferWaitlistSeats;
use App\Actions\Waitlist\ProcessWaitlists;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Enums\WaitlistStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\Slots\Pages\ViewSlot;
use App\Filament\Resources\Slots\RelationManagers\WaitlistRelationManager;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistOffer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A group slot $days ahead with every seat taken by confirmed bookings. */
function fullSlot(int $capacity = 2, int $days = 4): Slot
{
    $slot = nearSlot($days, 8, ['capacity' => $capacity]);
    Booking::factory()->count($capacity)->create(['slot_id' => $slot->id]);

    return $slot;
}

/** A customer with a credit pack and $riders riders. */
function waitlistCustomer(int $riders = 1): User
{
    $user = User::factory()->create();
    Rider::factory()->count($riders)->for($user)->create();
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(6), 'credits_total' => 8]);

    return $user;
}

/** Cancel one of the slot's confirmed bookings the way an admin does (frees a seat, runs the waitlist). */
function freeSeat(Slot $slot): void
{
    $booking = Booking::query()->where('slot_id', $slot->id)->where('status', BookingStatus::Confirmed)->firstOrFail();
    app(CancelBooking::class)->handle($booking, User::factory()->admin()->create(), 'Rider unwell', asAdmin: true);
}

beforeEach(function (): void {
    Notification::fake();
});

it('lets a signed-in customer join a full slot once', function (): void {
    $slot = fullSlot();
    $user = waitlistCustomer();

    $entry = app(JoinWaitlist::class)->handle($user, $slot->id, 2);

    expect($entry->status)->toBe(WaitlistStatus::Waiting)
        ->and($entry->seats)->toBe(2)
        ->and($entry->position())->toBe(1)
        ->and(Activity::query()->where('log_name', 'waitlist')->where('event', 'joined')->count())->toBe(1);

    expect(fn () => app(JoinWaitlist::class)->handle($user, $slot->id, 1))
        ->toThrow(BookingRejected::class, 'already on the waitlist');
});

it('refuses the waitlist when seats are free, the slot is private-only, closed to booking or the group is too big', function (): void {
    $user = waitlistCustomer();

    $open = nearSlot(4, 8, ['capacity' => 4]);
    expect(fn () => app(JoinWaitlist::class)->handle($user, $open->id, 1))->toThrow(BookingRejected::class, 'seats free');

    // Not enough for the whole group: joining is fine.
    Booking::factory()->count(3)->create(['slot_id' => $open->id]);
    expect(app(JoinWaitlist::class)->handle($user, $open->id, 2)->status)->toBe(WaitlistStatus::Waiting);

    $private = nearSlot(5, 8, ['capacity' => 2, 'private_only' => true]);
    expect(fn () => app(JoinWaitlist::class)->handle($user, $private->id, 1))->toThrow(BookingRejected::class);

    $tooBig = fullSlot(2, 6);
    expect(fn () => app(JoinWaitlist::class)->handle($user, $tooBig->id, 3))->toThrow(BookingRejected::class);

    $past = Slot::factory()->at(now()->subDay()->toImmutable())->create();
    expect(fn () => app(JoinWaitlist::class)->handle($user, $past->id, 1))->toThrow(BookingRejected::class);

    expect(fn () => app(JoinWaitlist::class)->handle(User::factory()->staff()->create(), $tooBig->id, 1))->toThrow(BookingRejected::class);
});

it('offers a freed seat to the first person in line, holds it and emails them', function (): void {
    $slot = fullSlot();
    $first = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);
    $second = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);

    freeSeat($slot);

    $first->refresh();
    expect($first->status)->toBe(WaitlistStatus::Offered)
        ->and($first->offer_expires_at?->diffInMinutes(now(), true))->toBeGreaterThan(119)->toBeLessThan(121)
        ->and($second->refresh()->status)->toBe(WaitlistStatus::Waiting)
        ->and(BookingHold::query()->where('waitlist_entry_id', $first->id)->value('seats'))->toBe(1)
        ->and($slot->fresh()?->seatsFree())->toBe(0); // the seat is held, nobody else can take it

    Notification::assertSentTo($first->user, WaitlistOffer::class, function (WaitlistOffer $n) use ($slot): bool {
        $html = (string) $n->toMail($n->entry->user)->render();

        return str_contains($html, e(route('book', ['slot' => $slot->id, 'riders' => 1]))) && str_contains($html, 'holding');
    });
    Notification::assertNotSentTo($second->user, WaitlistOffer::class);

    // Another customer can't take the held seat.
    $other = waitlistCustomer();
    expect(fn () => app(BookWithCredit::class)->handle($other, $slot->id, [$other->riders()->firstOrFail()->id]))
        ->toThrow(BookingRejected::class);
});

it('skips a bigger group for the first person whose group fits', function (): void {
    $slot = fullSlot(3);
    $big = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 2);
    $small = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);

    freeSeat($slot);

    expect($big->refresh()->status)->toBe(WaitlistStatus::Waiting)
        ->and($small->refresh()->status)->toBe(WaitlistStatus::Offered);

    // Two more seats free: the bigger group now fits and keeps its place.
    freeSeat($slot);
    freeSeat($slot);

    expect($big->refresh()->status)->toBe(WaitlistStatus::Offered)
        ->and((int) BookingHold::query()->whereNotNull('waitlist_entry_id')->sum('seats'))->toBe(3);
});

it('moves an expired offer on to the next person', function (): void {
    $slot = fullSlot(1);
    $first = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);
    $second = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);
    freeSeat($slot);

    $this->travel(119)->minutes();
    expect(app(ProcessWaitlists::class)->handle())->toBe(0)
        ->and($first->refresh()->status)->toBe(WaitlistStatus::Offered);

    $this->travel(2)->minutes();
    expect(app(ProcessWaitlists::class)->handle())->toBe(1)
        ->and($first->refresh()->status)->toBe(WaitlistStatus::Expired)
        ->and(BookingHold::query()->where('waitlist_entry_id', $first->id)->exists())->toBeFalse()
        ->and($second->refresh()->status)->toBe(WaitlistStatus::Offered);

    Notification::assertSentTo($second->user, WaitlistOffer::class);
});

it('uses the offer when the customer books with credits, and the seats are theirs', function (): void {
    $slot = fullSlot(2);
    $user = waitlistCustomer(2);
    $entry = app(JoinWaitlist::class)->handle($user, $slot->id, 2);
    freeSeat($slot);
    freeSeat($slot);
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Offered);

    $bookings = app(BookWithCredit::class)->handle($user, $slot->id, $user->riders()->pluck('id')->map(fn ($id): int => (int) $id)->all());

    expect($bookings)->toHaveCount(2)
        ->and($entry->refresh()->status)->toBe(WaitlistStatus::Booked)
        ->and($entry->booked_at)->not->toBeNull()
        ->and(BookingHold::query()->whereNotNull('waitlist_entry_id')->exists())->toBeFalse()
        ->and($slot->fresh()?->seatsFree())->toBe(0);
});

it('uses the offer for a paid booking: the order holds the seats', function (): void {
    $slot = fullSlot(1);
    $user = waitlistCustomer();
    $entry = app(JoinWaitlist::class)->handle($user, $slot->id, 1);
    freeSeat($slot);

    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), $slot->id, [$user->riders()->firstOrFail()->id]);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Booked)
        ->and(BookingHold::query()->where('order_id', $order->id)->value('seats'))->toBe(1)
        ->and(BookingHold::query()->whereNotNull('waitlist_entry_id')->exists())->toBeFalse();

    app(FulfilOrder::class)->handle($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('opens the booking flow on the offered slot for the customer it is held for', function (): void {
    $slot = fullSlot(1);
    $user = waitlistCustomer();
    app(JoinWaitlist::class)->handle($user, $slot->id, 1);
    freeSeat($slot);

    Livewire::actingAs($user)->withQueryParams(['slot' => $slot->id, 'use' => 'credits'])
        ->test('book.booking-flow')
        ->assertSet('step', 'riders');

    Livewire::actingAs(waitlistCustomer())->withQueryParams(['slot' => $slot->id, 'use' => 'credits'])
        ->test('book.booking-flow')
        ->assertSet('step', 'slot');
});

it('never offers past the booking cutoff, and closes the waitlist when booking ends', function (): void {
    app(UpdateSettings::class)->handle([SettingKey::MinNoticeHours->value => 2, SettingKey::WaitlistOfferHours->value => 3]);
    $slot = fullSlot(1, 1);
    $entry = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);

    // 5 hours before the start: the 3-hour offer ends at the 2-hour cutoff.
    $this->travelTo($slot->starts_at->subHours(5));
    freeSeat($slot);
    expect($entry->refresh()->offer_expires_at?->equalTo($slot->starts_at->subHours(2)))->toBeTrue();

    $late = app(JoinWaitlist::class)->handle(waitlistCustomer(), ($other = fullSlot(1, 1))->id, 1);
    $this->travelTo($other->starts_at->subHours(2)->addMinutes(5));
    app(ProcessWaitlists::class)->handle();

    expect($late->refresh()->status)->toBe(WaitlistStatus::Closed)
        ->and($late->closed_reason)->toBe('Booking has closed for this session');
});

it('closes the waitlist when the slot is cancelled, and waits while it is closed', function (): void {
    $slot = fullSlot(1);
    $entry = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);
    $admin = User::factory()->admin()->create();

    app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Closed, $admin);
    Booking::query()->where('slot_id', $slot->id)->update(['status' => BookingStatus::Cancelled]);
    app(ProcessWaitlists::class)->handle();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Waiting);

    app(ChangeSlotStatus::class)->handle($slot->refresh(), SlotStatus::Cancelled, $admin, 'Storm');
    app(ProcessWaitlists::class)->handle();
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Closed)
        ->and($entry->closed_reason)->toBe('The session was cancelled');
});

it('hands seats left by a move to the waitlist', function (): void {
    $user = waitlistCustomer();
    $slot = nearSlot(5, 8, ['capacity' => 1]);
    $booking = app(BookWithCredit::class)->handle($user, $slot->id, [$user->riders()->firstOrFail()->id])->sole();
    $entry = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);

    app(RescheduleBooking::class)->handle($booking, nearSlot(6)->id, $user);

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Offered);
});

it('frees the offered seats when the customer leaves, for the next person', function (): void {
    $slot = fullSlot(1);
    $first = app(JoinWaitlist::class)->handle($leaver = waitlistCustomer(), $slot->id, 1);
    $second = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);
    freeSeat($slot);

    app(LeaveWaitlist::class)->handle($first->refresh(), $leaver);

    expect($first->refresh()->status)->toBe(WaitlistStatus::Cancelled)
        ->and($second->refresh()->status)->toBe(WaitlistStatus::Offered);

    expect(fn () => app(LeaveWaitlist::class)->handle($second, User::factory()->staff()->create(), 'No'))->toThrow(BookingRejected::class);
    expect(fn () => app(LeaveWaitlist::class)->handle($second, User::factory()->admin()->create()))->toThrow(BookingRejected::class, 'reason');
});

it('never holds the same seat twice when offers run again', function (): void {
    $slot = fullSlot(1);
    $entries = collect(range(1, 3))->map(fn (): WaitlistEntry => app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1));
    freeSeat($slot);

    app(OfferWaitlistSeats::class)->handle($slot->id);
    app(ProcessWaitlists::class)->handle();

    expect(WaitlistEntry::query()->where('status', WaitlistStatus::Offered)->count())->toBe(1)
        ->and((int) BookingHold::query()->where('slot_id', $slot->id)->sum('seats'))->toBe(1)
        ->and($entries->first()?->refresh()->status)->toBe(WaitlistStatus::Offered);
});

it('runs every minute', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e): bool => str_contains((string) $e->command, 'waitlist:process'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('* * * * *');
    $this->artisan('waitlist:process')->assertSuccessful();
});

it('lets customers join, see and leave their waitlists from the account', function (): void {
    $slot = fullSlot(2);
    $user = waitlistCustomer();

    $this->get(route('account.waitlist.create', $slot))->assertRedirect(route('login'));

    $this->actingAs($user)->get(route('account.waitlist.create', ['slot' => $slot, 'riders' => 2]))
        ->assertOk()->assertSee('Join the waitlist')->assertSee('<option value="2" selected', false);

    $this->actingAs($user)->post(route('account.waitlist.store', $slot), ['seats' => 2])
        ->assertRedirect(route('account.waitlist'));

    $entry = WaitlistEntry::query()->where('user_id', $user->id)->sole();

    $this->actingAs($user)->get(route('account.waitlist'))->assertOk()->assertSee('number 1 in line')->assertSee($slot->label());

    // Someone else's entry is a 404.
    $this->actingAs(waitlistCustomer())->delete(route('account.waitlist.destroy', $entry->id))->assertNotFound();

    $this->actingAs($user)->delete(route('account.waitlist.destroy', $entry->id))->assertRedirect(route('account.waitlist'));
    expect($entry->refresh()->status)->toBe(WaitlistStatus::Cancelled);
});

it('shows Join the waitlist on full group slots in the schedule', function (): void {
    $slot = fullSlot(1);

    $this->get(route('schedule', ['week' => $slot->localStartsAt()->startOfWeek()->format('Y-m-d')]))
        ->assertOk()
        ->assertSee(route('account.waitlist.create', $slot), false);
});

it('shows the waitlist on the slot page; admins remove with a reason', function (): void {
    $slot = fullSlot(1);
    $entry = app(JoinWaitlist::class)->handle(waitlistCustomer(), $slot->id, 1);
    $admin = User::factory()->admin()->withMfa()->create();

    Livewire::actingAs($admin)
        ->test(WaitlistRelationManager::class, ['ownerRecord' => $slot, 'pageClass' => ViewSlot::class])
        ->assertCanSeeTableRecords([$entry])
        ->callTableAction('remove', $entry, ['reason' => 'Asked by phone'])
        ->assertHasNoTableActionErrors();

    expect($entry->refresh()->status)->toBe(WaitlistStatus::Cancelled)
        ->and($entry->closed_reason)->toBe('Asked by phone');

    $staff = User::factory()->staff()->withMfa()->create();
    Livewire::actingAs($staff)
        ->test(WaitlistRelationManager::class, ['ownerRecord' => $slot, 'pageClass' => ViewSlot::class])
        ->assertTableActionHidden('remove', $entry);

    expect(WaitlistRelationManager::canViewForRecord($slot, ViewSlot::class))->toBeTrue();
});
