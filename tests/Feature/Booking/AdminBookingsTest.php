<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\RelationManagers\CreditPacksRelationManager;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Rider;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

function bookingAdmin(): User
{
    $admin = User::factory()->admin()->withMfa()->create();
    test()->actingAs($admin);

    return $admin;
}

it('lists bookings for admins and staff', function (string $role): void {
    $user = $role === 'admin' ? User::factory()->admin()->withMfa()->create() : User::factory()->staff()->withMfa()->create();
    $booking = Booking::factory()->create(['slot_id' => nearSlot()->id]);

    $this->actingAs($user)->get('/test-admin/bookings')->assertOk()->assertSee($booking->ticket_code);
})->with(['admin', 'staff']);

it('keeps the booking tools away from staff', function (): void {
    $this->actingAs(User::factory()->staff()->withMfa()->create());
    $booking = Booking::factory()->create(['slot_id' => nearSlot()->id]);

    Livewire::test(ListBookings::class)
        ->assertActionHidden('manual_booking')
        ->assertActionHidden(TestAction::make('move')->table($booking))
        ->assertActionHidden(TestAction::make('cancel_booking')->table($booking));
});

it('moves a booking with a reason', function (): void {
    bookingAdmin();
    $booking = Booking::factory()->create(['slot_id' => nearSlot(1)->id]);
    $target = nearSlot(4);

    Livewire::test(ListBookings::class)
        ->callAction(TestAction::make('move')->table($booking), data: ['slot_id' => $target->id, 'reason' => ''])
        ->assertHasFormErrors(['reason' => 'required']);

    Livewire::test(ListBookings::class)
        ->callAction(TestAction::make('move')->table($booking), data: ['slot_id' => $target->id, 'reason' => 'Customer asked by phone'])
        ->assertNotified('Booking moved');

    expect($booking->fresh()?->slot_id)->toBe($target->id);
});

it('cancels a credit booking after the cutoff and can give the credit back', function (): void {
    bookingAdmin();
    $customer = User::factory()->create();
    $rider = Rider::factory()->for($customer)->create();
    $pack = CreditPack::factory()->create(['order_id' => Order::factory()->for($customer)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(4)]);
    $booking = app(BookWithCredit::class)->handle($customer, nearSlot(1)->id, [$rider->id])->sole();

    Livewire::test(ListBookings::class)
        ->callAction(TestAction::make('cancel_booking')->table($booking), data: ['reason' => 'Lame horse', 'return_credit' => true])
        ->assertNotified('Booking cancelled');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($pack->fresh()?->credits_used)->toBe(0);
});

it('marks attendance once the session has started', function (): void {
    bookingAdmin();
    $slot = nearSlot(1);
    $booking = Booking::factory()->create(['slot_id' => $slot->id]);

    Livewire::test(ListBookings::class)->assertActionHidden(TestAction::make('attended')->table($booking));

    $this->travelTo($slot->starts_at->addMinutes(10));

    Livewire::test(ListBookings::class)
        ->filterTable('upcoming', false)
        ->callAction(TestAction::make('attended')->table($booking))
        ->assertNotified('Marked Attended');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Attended);
});

it('adds a no-charge manual booking with a reason, or with a credit', function (): void {
    bookingAdmin();
    $customer = User::factory()->create();
    $rider = Rider::factory()->for($customer)->create();
    $pack = CreditPack::factory()->create(['order_id' => Order::factory()->for($customer)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(4)]);
    $slot = nearSlot();

    Livewire::test(ListBookings::class)
        ->callAction('manual_booking', data: ['rider_id' => $rider->id, 'slot_id' => $slot->id, 'credit_pack_id' => null, 'reason' => ''])
        ->assertHasFormErrors(['reason']);

    Livewire::test(ListBookings::class)
        ->callAction('manual_booking', data: ['rider_id' => $rider->id, 'slot_id' => $slot->id, 'credit_pack_id' => null, 'reason' => 'Walk-in trial'])
        ->assertNotified('Booked '.$rider->name);

    Livewire::test(ListBookings::class)
        ->callAction('manual_booking', data: ['rider_id' => $rider->id, 'slot_id' => nearSlot(4)->id, 'credit_pack_id' => $pack->id])
        ->assertNotified('Booked '.$rider->name);

    expect(Booking::query()->whereNull('credit_pack_id')->whereNull('order_id')->count())->toBe(1)
        ->and($pack->fresh()?->credits_used)->toBe(1);
});

it('extends a credit pack from the customer page', function (): void {
    bookingAdmin();
    $customer = User::factory()->create();
    $pack = CreditPack::factory()->create(['order_id' => Order::factory()->for($customer)->status(OrderStatus::Paid), 'expires_at' => now()->addWeek()]);

    Livewire::test(CreditPacksRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => EditCustomer::class])
        ->assertSee('8 of 8')
        ->callAction(TestAction::make('extend')->table($pack), data: ['expires_on' => now('Asia/Karachi')->addWeeks(3)->format('Y-m-d'), 'reason' => 'Injury'])
        ->assertNotified('Pack extended');

    expect($pack->fresh()?->expires_at->isAfter(now()->addWeeks(2)))->toBeTrue();
});
