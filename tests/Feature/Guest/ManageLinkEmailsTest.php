<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\WeatherNoticeTemplate;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\PrivateSessionRequest;
use App\Models\Rider;
use App\Models\SlotChangeNotice;
use App\Models\User;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingConfirmed;
use App\Notifications\BookingMoved;
use App\Notifications\CreditsExpiringSoon;
use App\Notifications\CustomerNotification;
use App\Notifications\OrderConfirmed;
use App\Notifications\OrderNotFulfilled;
use App\Notifications\PrivateRequestApproved;
use App\Notifications\PrivateRequestReceived;
use App\Notifications\SessionReminder;
use App\Notifications\SlotCancelledNotice;
use App\Notifications\SlotTimeChanged;
use App\Notifications\ThankYou;
use App\Notifications\TransferExpired;
use App\Notifications\TransferReceived;
use App\Notifications\TransferRejected;
use App\Notifications\WeatherNotice;
use App\Support\ManageLink;
use App\Support\PrivateRequestLink;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/*
| Every booking email carries the signed "Manage your booking" link of its
| order (guest checkout), for guests and signed-in customers alike.
| Helpers are prefixed `mle`.
*/

/** A paid order with one confirmed booking. */
function mleBooking(): Booking
{
    $user = User::factory()->create();
    $slot = nearSlot(5);
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create(['paid_at' => now()]);
    $rider = Rider::factory()->for($user)->create();
    $order->items()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'unit_price_minor' => 1_000_000]);

    return Booking::factory()->create(['slot_id' => $slot->id, 'order_id' => $order->id, 'rider_id' => $rider->id]);
}

function mleHtml(CustomerNotification $notification, User $user): string
{
    return html_entity_decode((string) $notification->toMail($user)->render());
}

it('puts the order’s manage link in every booking email', function (Closure $make): void {
    $booking = mleBooking();
    $order = $booking->order;

    $html = mleHtml($make($booking), $booking->user);

    expect($html)->toContain(ManageLink::forOrder($order))
        ->and($html)->toContain('Manage your booking');
})->with([
    'order confirmed' => fn (Booking $b) => new OrderConfirmed($b->order),
    'booking confirmed' => fn (Booking $b) => new BookingConfirmed(new EloquentCollection([$b])),
    'booking moved' => fn (Booking $b) => new BookingMoved(new EloquentCollection([$b]), nearSlot(9)),
    'booking cancelled' => fn (Booking $b) => new BookingCancelled($b, false),
    'transfer received' => fn (Booking $b) => new TransferReceived($b->order),
    'transfer rejected' => fn (Booking $b) => new TransferRejected($b->order, 'No match'),
    'transfer expired' => fn (Booking $b) => new TransferExpired($b->order),
    'not fulfilled' => fn (Booking $b) => new OrderNotFulfilled($b->order, true),
    'reminder' => fn (Booking $b) => new SessionReminder($b->slot, new EloquentCollection([$b])),
    'slot cancelled' => fn (Booking $b) => new SlotCancelledNotice($b->slot, new EloquentCollection([$b]), 0, true),
    'thank you' => fn (Booking $b) => new ThankYou($b->slot, new EloquentCollection([$b])),
    'weather notice' => fn (Booking $b) => new WeatherNotice($b->slot, WeatherNoticeTemplate::Update, 'Light rain expected.', new EloquentCollection([$b])),
    'schedule change' => fn (Booking $b) => new SlotTimeChanged(SlotChangeNotice::factory()->create(['slot_id' => $b->slot_id, 'user_id' => $b->user_id])),
]);

it('links a credit booking to the order that bought the package', function (): void {
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create(['paid_at' => now()]);
    $pack = CreditPack::factory()->create(['order_id' => $order->id]);
    $booking = Booking::factory()->create(['rider_id' => Rider::factory()->for($user)->create()->id, 'credit_pack_id' => $pack->id]);

    expect(mleHtml(new BookingConfirmed(new EloquentCollection([$booking])), $user))->toContain(ManageLink::forOrder($order))
        ->and(mleHtml(new CreditsExpiringSoon($pack), $user))->toContain(ManageLink::forPackBooking($pack));
});

it('shows no manage link for a no-charge booking without an order', function (): void {
    $booking = Booking::factory()->create();

    expect(mleHtml(new BookingConfirmed(new EloquentCollection([$booking])), $booking->user))->not->toContain('Manage your booking');
});

it('gives private requests a follow link and pays through the order’s manage link', function (): void {
    $order = Order::factory()->status(OrderStatus::PendingPayment)->create(['expires_at' => now()->addHours(2)]);
    $request = PrivateSessionRequest::factory()->create(['user_id' => $order->user_id, 'order_id' => $order->id, 'pay_by' => now()->addHours(2)]);

    expect(mleHtml(new PrivateRequestReceived($request), $request->user))->toContain(PrivateRequestLink::for($request))
        ->and(mleHtml(new PrivateRequestApproved($request), $request->user))->toContain(ManageLink::forOrder($order));
});
