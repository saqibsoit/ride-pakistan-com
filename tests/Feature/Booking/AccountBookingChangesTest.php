<?php

declare(strict_types=1);

use App\Actions\Booking\BookWithCredit;
use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;

/** A customer with one credit booking $days ahead. */
function creditBooking(int $days = 5): Booking
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'expires_at' => now()->addWeeks(4)]);

    return app(BookWithCredit::class)->handle($user, nearSlot($days)->id, [$rider->id])->sole();
}

it('offers Move and Cancel on credit bookings only', function (): void {
    $booking = creditBooking();
    $user = $booking->user;

    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(), nearSlot(6)->id, [Rider::factory()->for($user)->create()->id]);
    app(FulfilOrder::class)->handle($order);
    $paid = Booking::query()->where('order_id', $order->id)->sole();

    $html = $this->actingAs($user)->get('/account/bookings')->assertOk()->getContent();

    expect($html)->toContain(route('account.bookings.reschedule.edit', $booking->ticket_code))
        ->and($html)->toContain(route('account.bookings.cancel', $booking->ticket_code))
        ->and($html)->not->toContain(route('account.bookings.cancel', $paid->ticket_code))
        ->and($html)->toContain('To change,');

    $this->actingAs($user)->get(route('account.bookings.cancel', $paid->ticket_code))->assertForbidden();
});

it('cancels a credit booking before the cutoff and gives the credit back', function (): void {
    $booking = creditBooking(5);

    $this->actingAs($booking->user)->get(route('account.bookings.cancel', $booking->ticket_code))
        ->assertOk()
        ->assertSee('Your credit comes back.');

    $this->actingAs($booking->user)->post(route('account.bookings.cancel.store', $booking->ticket_code))
        ->assertRedirect(route('account.bookings'))
        ->assertSessionHas('status', 'booking-cancelled-credit-back');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->creditPack?->fresh()?->credits_used)->toBe(0);
});

it('warns that the credit is used after the cutoff', function (): void {
    $booking = creditBooking(1);

    $this->actingAs($booking->user)->get(route('account.bookings.cancel', $booking->ticket_code))
        ->assertSee('Your credit won’t come back.');

    $this->actingAs($booking->user)->post(route('account.bookings.cancel.store', $booking->ticket_code))
        ->assertSessionHas('status', 'booking-cancelled');

    expect($booking->creditPack?->fresh()?->credits_used)->toBe(1);
});

it('moves a credit booking to another slot', function (): void {
    $booking = creditBooking(5);
    $target = nearSlot(6);

    $this->actingAs($booking->user)->get(route('account.bookings.reschedule.edit', $booking->ticket_code))
        ->assertOk()
        ->assertSee('value="'.$target->id.'"', false);

    $this->actingAs($booking->user)->post(route('account.bookings.reschedule', $booking->ticket_code), ['slot' => $target->id])
        ->assertRedirect(route('account.bookings'));

    expect($booking->fresh()?->slot_id)->toBe($target->id);
});

it('explains when it is too late to move', function (): void {
    $booking = creditBooking(1);

    $this->actingAs($booking->user)->get(route('account.bookings.reschedule.edit', $booking->ticket_code))
        ->assertSee('It’s too late to move this session.');

    $this->actingAs($booking->user)->post(route('account.bookings.reschedule', $booking->ticket_code), ['slot' => nearSlot(6)->id])
        ->assertSessionHasErrors(['slot' => 'Changes are possible up to 48 hours before the session.']);
});

it('never lets one customer change another customer’s booking', function (string $route, string $method): void {
    $booking = creditBooking();
    $other = User::factory()->create();

    $this->actingAs($other)->{$method}(route($route, $booking->ticket_code), ['slot' => nearSlot(6)->id])->assertNotFound();

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
})->with([
    ['account.bookings.cancel', 'get'],
    ['account.bookings.cancel.store', 'post'],
    ['account.bookings.reschedule.edit', 'get'],
    ['account.bookings.reschedule', 'post'],
]);

it('links to booking with credits from the credits page', function (): void {
    $booking = creditBooking();

    $this->actingAs($booking->user)->get('/account/credits')->assertSee(e(route('book', ['use' => 'credits'])), false);
});
