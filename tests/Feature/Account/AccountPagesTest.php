<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * A customer with one of everything: an upcoming and a past booking, a
 * paid order with an item, and an active credit pack.
 *
 * @return array{user: User, rider: Rider, upcoming: Booking, past: Booking, order: Order, pack: CreditPack}
 */
function customerWithHistory(string $name = 'Sana Malik', int $pastWeeksAgo = 2): array
{
    $user = User::factory()->create(['name' => $name]);
    $rider = Rider::factory()->for($user)->create(['name' => $name.' rider']);
    $order = Order::factory()->for($user)->status(OrderStatus::Paid)->create(['amount_minor' => 1_000_000]);
    $slot = Slot::factory()->create();
    OrderItem::factory()->create(['order_id' => $order->id, 'slot_id' => $slot->id, 'rider_id' => $rider->id, 'unit_price_minor' => 1_000_000]);

    $upcoming = Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'order_id' => $order->id]);
    $past = Booking::factory()->status(BookingStatus::Attended)->create([
        'slot_id' => Slot::factory()->at(CarbonImmutable::now('Asia/Karachi')->subWeeks($pastWeeksAgo)->setTime(8, 0)),
        'rider_id' => $rider->id,
    ]);
    $pack = CreditPack::factory()->create([
        'order_id' => Order::factory()->for($user)->status(OrderStatus::Paid),
        'credits_total' => 8,
        'credits_used' => 3,
        'expires_at' => now()->addWeeks(4),
    ]);

    return compact('user', 'rider', 'upcoming', 'past', 'order', 'pack');
}

it('sends guests to the sign-in page', function (string $uri): void {
    $this->get($uri)->assertRedirect(route('login'));
})->with([
    '/account',
    '/account/bookings',
    '/account/tickets',
    '/account/tickets/ABC',
    '/account/credits',
    '/account/orders',
    '/account/orders/RP-ABCDEF',
    '/account/riders',
    '/account/profile',
    '/account/privacy',
]);

it('shows the dashboard with the next session and credits left', function (): void {
    ['user' => $user, 'upcoming' => $upcoming, 'pack' => $pack] = customerWithHistory();

    $this->actingAs($user)->get('/account')
        ->assertOk()
        ->assertSee('Hello, Sana.')
        ->assertSee($upcoming->slot->label())
        ->assertSee('5 of 8 left, use by')
        ->assertSee($pack->expires_at->setTimezone('Asia/Karachi')->format('j F Y'))
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('noindex', false);
});

it('asks a new customer for their name', function (): void {
    $this->actingAs(User::factory()->passwordless()->create())->get('/account')
        ->assertOk()
        ->assertSee('Your account')
        ->assertSee('Tell us your name.');
});

it('lists upcoming and past bookings', function (): void {
    ['user' => $user, 'upcoming' => $upcoming, 'past' => $past] = customerWithHistory();

    $this->actingAs($user)->get('/account/bookings')
        ->assertOk()
        ->assertSeeInOrder(['Upcoming', $upcoming->slot->label(), 'Past', $past->slot->label()])
        ->assertSee('Attended');
});

it('shows tickets for confirmed upcoming bookings only', function (): void {
    ['user' => $user, 'upcoming' => $upcoming, 'past' => $past] = customerWithHistory();

    $this->actingAs($user)->get('/account/tickets')
        ->assertOk()
        ->assertSee(route('account.tickets.show', $upcoming->ticket_code))
        ->assertDontSee($past->slot->label());

    $this->actingAs($user)->get(route('account.tickets.show', $upcoming->ticket_code))
        ->assertOk()
        ->assertSee($upcoming->ticket_code)
        ->assertSee($upcoming->rider->name);
});

it('shows credit packs with expiry', function (): void {
    ['user' => $user] = customerWithHistory();

    $this->actingAs($user)->get('/account/credits')
        ->assertOk()
        ->assertSee('5 of 8 credits left');
});

it('lists orders and shows a receipt for a paid order', function (): void {
    ['user' => $user, 'order' => $order, 'rider' => $rider] = customerWithHistory();

    $this->actingAs($user)->get('/account/orders')
        ->assertOk()
        ->assertSee($order->reference)
        ->assertSee('Paid');

    $this->actingAs($user)->get(route('account.orders.show', $order->reference))
        ->assertOk()
        ->assertSee('Receipt '.$order->reference)
        ->assertSee($rider->name)
        ->assertSee('PKR 10,000')
        ->assertSee('Destination Pakistan');
});

it('never shows one customer another customer’s data', function (): void {
    ['user' => $owner, 'upcoming' => $booking, 'order' => $order, 'rider' => $rider] = customerWithHistory('Owner Person');
    ['user' => $other] = customerWithHistory('Other Person', 3);

    $this->actingAs($other);

    $this->get(route('account.orders.show', $order->reference))->assertNotFound();
    $this->get(route('account.tickets.show', $booking->ticket_code))->assertNotFound();

    foreach (['/account', '/account/bookings', '/account/tickets', '/account/orders', '/account/credits', '/account/riders'] as $uri) {
        $this->get($uri)->assertOk()
            ->assertDontSee($order->reference)
            ->assertDontSee($booking->ticket_code)
            ->assertDontSee($rider->name)
            ->assertDontSee('Owner Person');
    }
});

it('does not open account pages for a deleted account', function (): void {
    $user = User::factory()->create();
    $user->delete();

    // A session that still names the account: the user provider skips
    // soft-deleted accounts, so the request is treated as a guest.
    $this->withSession([Auth::guard('web')->getName() => $user->id])
        ->get('/account')
        ->assertRedirect(route('login'));
});
