<?php

declare(strict_types=1);

use App\Actions\Guest\RevokeManageLinks;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Livewire\Manage\PackBooking;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\ManageLinkReissued;
use App\Support\ManageLink;
use App\Support\ManageLinkWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| "Manage your booking" links (guest checkout, decided 2026-11-12). Helpers
| are prefixed `ml`.
*/

beforeEach(function (): void {
    Notification::fake();
});

/** A paid single-session order with one confirmed booking at $slot. */
function mlPaidOrder(?Slot $slot = null, string $ticket = 'TICKETPAID01'): Order
{
    $user = User::factory()->create(['name' => 'Account Owner']);
    $slot ??= nearSlot(5);
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create(['paid_at' => now(), 'placed_as_guest' => true, 'contact_name' => 'Guest Name']);
    $rider = Rider::factory()->for($user)->create(['name' => 'Zara Malik']);
    $order->items()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'unit_price_minor' => 1_000_000]);
    Booking::factory()->create(['slot_id' => $slot->id, 'order_id' => $order->id, 'rider_id' => $rider->id, 'ticket_code' => $ticket]);

    return $order->refresh();
}

/** A paid package order with one credit booking at $slot. */
function mlPackOrder(?Slot $slot = null): array
{
    $user = User::factory()->create();
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create(['paid_at' => now()]);
    $pack = CreditPack::factory()->create(['order_id' => $order->id, 'credits_total' => 8, 'credits_used' => 1, 'expires_at' => now()->addDays(42)]);
    $rider = Rider::factory()->for($user)->create(['name' => 'Omar Malik']);
    $booking = Booking::factory()->create(['slot_id' => ($slot ?? nearSlot(6))->id, 'rider_id' => $rider->id, 'credit_pack_id' => $pack->id, 'ticket_code' => 'TICKETCRED01']);

    return [$order->refresh(), $pack, $booking];
}

it('opens the order from a signed link and redirects to the plain page', function (): void {
    $order = mlPaidOrder();

    $this->get(ManageLink::forOrder($order))->assertRedirect(route('manage.show', $order->reference));

    $this->get(route('manage.show', $order->reference))
        ->assertOk()
        ->assertSee('TICKETPAID01')
        ->assertSee('Zara Malik')
        ->assertSee('To change this session')
        ->assertDontSee('Account Owner')
        ->assertDontSee($order->user->email);

    // The same browser can also pay or see the checkout of that one order.
    $this->get(route('checkout.status', $order->reference))->assertOk();
});

it('shows the same page for a tampered, stale, unknown or missing link', function (): void {
    $order = mlPaidOrder();
    $other = mlPaidOrder(nearSlot(7), 'TICKETPAID02');
    $good = ManageLink::forOrder($order);

    $tampered = str_replace($order->reference, $other->reference, $good);
    $stale = ManageLink::forOrder($order);
    $order->forceFill(['manage_link_version' => 2])->save();

    foreach ([$tampered, $stale, $good.'x', route('manage.open', ['reference' => $order->reference, 'v' => 2])] as $url) {
        $this->get($url)->assertForbidden()->assertSee('This link doesn’t work any more');
    }

    // No grant was made by any of them.
    $this->get(route('manage.show', $order->reference))->assertForbidden()->assertSee('This link doesn’t work any more');
    $this->get(route('manage.show', 'RP-NOSUCH'))->assertForbidden()->assertSee('This link doesn’t work any more');
});

it('stops working 7 days after the last session, at the exact edge', function (): void {
    $slot = nearSlot(5);
    $order = mlPaidOrder($slot);
    $link = ManageLink::forOrder($order);
    $end = CarbonImmutable::parse($slot->ends_at)->addDays(ManageLinkWindow::DAYS_AFTER);

    expect(app(ManageLinkWindow::class)->endsAt($order))->toEqual($end);

    $this->travelTo($end->subSecond());
    $this->get($link)->assertRedirect(route('manage.show', $order->reference));
    $this->get(route('manage.show', $order->reference))->assertOk();

    $this->travelTo($end);
    $this->get(route('manage.show', $order->reference))->assertForbidden();
    $this->get($link)->assertForbidden();
});

it('follows a moved session and the package expiry for the window', function (): void {
    [$order, $pack, $booking] = mlPackOrder(nearSlot(3));
    $window = app(ManageLinkWindow::class);

    expect($window->endsAt($order))->toEqual(CarbonImmutable::parse($pack->expires_at)->addDays(7));

    $late = nearSlot(60);
    $booking->update(['slot_id' => $late->id]);

    expect($window->endsAt($order->refresh()))->toEqual(CarbonImmutable::parse($late->ends_at)->addDays(7));
});

it('keeps an uploaded transfer’s order open while it waits for review', function (): void {
    $order = Order::factory()->status(OrderStatus::AwaitingVerification)->create(['expires_at' => now()->subDays(30), 'created_at' => now()->subDays(40)]);

    expect(app(ManageLinkWindow::class)->isOpen($order))->toBeTrue();
});

it('revokes every link and every browser that opened one, and emails a new link', function (): void {
    $order = mlPaidOrder();
    $old = ManageLink::forOrder($order);
    $this->get($old);

    $this->post(route('manage.revoke', $order->reference))->assertRedirect(route('manage.link'));

    $order->refresh();
    expect($order->manage_link_version)->toBe(2);
    $this->get(route('manage.show', $order->reference))->assertForbidden();
    $this->get($old)->assertForbidden();

    Notification::assertSentTo($order->user, ManageLinkReissued::class, function (ManageLinkReissued $n) use ($order): bool {
        return $n->order->is($order) && $n->revoked;
    });

    $this->get(ManageLink::forOrder($order))->assertRedirect(route('manage.show', $order->reference));

    expect(Activity::query()->where('event', 'manage_link_revoked')->where('subject_id', $order->id)->exists())->toBeTrue();
});

it('lets admins revoke with a reason and nobody else', function (): void {
    $order = mlPaidOrder();
    $admin = User::factory()->admin()->create();

    app(RevokeManageLinks::class)->handle($order, $admin, 'Customer reported a forwarded email');
    expect($order->refresh()->manage_link_version)->toBe(2);

    expect(fn () => app(RevokeManageLinks::class)->handle($order, $admin, '  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(RevokeManageLinks::class)->handle($order, User::factory()->staff()->create(), 'x'))->toThrow(InvalidArgumentException::class);
});

it('moves and cancels a credit booking within the cutoff, and nothing else', function (): void {
    [$order, $pack, $booking] = mlPackOrder(nearSlot(6));
    $target = nearSlot(8);
    $this->get(ManageLink::forOrder($order));

    $this->get(route('manage.show', $order->reference))->assertSee('Move')->assertSee('Cancel');

    $this->post(route('manage.reschedule.store', [$order->reference, $booking->ticket_code]), ['slot' => $target->id])
        ->assertRedirect(route('manage.show', $order->reference));
    expect($booking->refresh()->slot_id)->toBe($target->id);

    $this->post(route('manage.cancel.store', [$order->reference, $booking->ticket_code]))->assertRedirect(route('manage.show', $order->reference));
    expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($pack->refresh()->credits_used)->toBe(0);

    // A paid single session changes only through us; another order's ticket is unknown here.
    $paid = mlPaidOrder(nearSlot(10));
    $this->get(ManageLink::forOrder($paid));
    $this->post(route('manage.cancel.store', [$paid->reference, 'TICKETPAID01']))->assertNotFound();
    $this->post(route('manage.cancel.store', [$order->reference, 'TICKETPAID01']))->assertNotFound();
});

it('books package credits from the link, spending only that package', function (): void {
    [$order, $pack] = mlPackOrder();
    $other = CreditPack::factory()->create(['order_id' => Order::factory()->for($order->user)->status(OrderStatus::Paid)->create()->id, 'expires_at' => now()->addDays(10)]);
    $slot = nearSlot(9);

    $this->get(ManageLink::forPackBooking($pack))->assertRedirect(route('manage.book', $order->reference));
    $this->get(route('manage.book', $order->reference))->assertOk()->assertSee('Omar Malik');

    Livewire::test(PackBooking::class, ['reference' => $order->reference])
        ->set('slotId', $slot->id)
        ->set('riderIds', [Rider::query()->where('name', 'Omar Malik')->value('id')])
        ->call('book')
        ->assertRedirect(route('manage.show', $order->reference));

    expect($pack->refresh()->credits_used)->toBe(2)
        ->and($other->refresh()->credits_used)->toBe(0)
        ->and(Booking::query()->where('slot_id', $slot->id)->sole()->credit_pack_id)->toBe($pack->id);
});

it('never offers the account’s other riders on the package page', function (): void {
    [$order] = mlPackOrder();
    $secret = Rider::factory()->for($order->user)->create(['name' => 'Secret Sibling']);
    $this->get(ManageLink::forOrder($order));

    $this->get(route('manage.book', $order->reference))->assertDontSee('Secret Sibling');

    Livewire::test(PackBooking::class, ['reference' => $order->reference])
        ->set('slotId', nearSlot(9)->id)
        ->set('riderIds', [$secret->id])
        ->call('book')
        ->assertHasErrors('riderIds');
});

it('emails a new link only when the email and reference match, with the same answer either way', function (): void {
    $order = mlPaidOrder();

    $this->post(route('manage.link.send'), ['email' => 'nobody@example.com', 'reference' => $order->reference])
        ->assertRedirect(route('manage.link'));
    $this->post(route('manage.link.send'), ['email' => strtoupper($order->user->email), 'reference' => strtolower($order->reference)])
        ->assertRedirect(route('manage.link'));

    Notification::assertSentToTimes($order->user, ManageLinkReissued::class, 1);
    $this->get(route('manage.link'))->assertSee('If that email and reference match a booking');
});

it('lets an admin revoke the links from the order page with a reason', function (): void {
    $order = mlPaidOrder();
    $admin = User::factory()->admin()->withMfa()->create();

    Livewire::actingAs($admin)->test(ViewOrder::class, ['record' => $order->reference])
        ->assertSee('Booked as (guest)')
        ->callAction('revoke_manage_links', data: ['reason' => 'Email forwarded to the wrong person']);

    expect($order->refresh()->manage_link_version)->toBe(2);
    Notification::assertSentTo($order->user, ManageLinkReissued::class);
    // Staff can't open orders at all; RevokeManageLinks refuses non-admins (tested above).
});

it('lets the signed-in customer stop the links from their account order page', function (): void {
    $order = mlPaidOrder();

    $this->actingAs($order->user)->get(route('account.orders.show', $order->reference))->assertSee('Stop the links for '.$order->reference);
    $this->actingAs($order->user)->post(route('manage.revoke', $order->reference))->assertRedirect(route('manage.link'));

    expect($order->refresh()->manage_link_version)->toBe(2);
});

it('shows the WhatsApp number typed for a guest booking on the run sheet', function (): void {
    $order = mlPaidOrder();
    $order->forceFill(['contact_whatsapp' => '+923001112233'])->save();
    $booking = $order->bookings()->sole();

    $this->actingAs(User::factory()->staff()->withMfa()->create())
        ->get(route('team.sessions.show', $booking->slot_id))
        ->assertOk()
        ->assertSee('+923001112233');
});
