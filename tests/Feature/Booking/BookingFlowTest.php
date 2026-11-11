<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Enums\OrderStatus;
use App\Livewire\Book\BookingFlow;
use App\Mail\LoginCodeMail;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->single = Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    $this->private = Product::factory()->private(1_500_000)->create(['slug' => 'private-session', 'name' => 'Private session']);
    $this->package = Product::factory()->package(8, 6_000_000, 42)->create(['slug' => '8-class-package', 'name' => '8-class package']);
});

it('renders the flow on /book', function (): void {
    $this->get('/book')->assertOk()->assertSeeLivewire(BookingFlow::class)->assertSee('Choose your session.');
});

it('books a single session end to end for a signed-in customer', function (): void {
    $user = User::factory()->create();
    $riders = Rider::factory()->count(2)->for($user)->create();
    $slot = nearSlot();

    Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->assertSet('step', 'slot')
        ->assertSee($slot->localStartsAt()->format('l j F'))
        ->call('chooseSlot', $slot->id)
        ->assertSet('step', 'riders')
        ->set('riderIds', $riders->modelKeys())
        ->call('confirmRiders')
        ->assertSet('step', 'review')
        ->assertSee('PKR 20,000')
        ->call('placeOrder')
        ->assertRedirect(route('checkout.show', ['reference' => Order::query()->sole()->reference]));

    $order = Order::query()->sole();
    expect($order->amount_minor)->toBe(2_000_000)
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and(BookingHold::query()->sole()->seats)->toBe(2);
});

it('starts part-way from the schedule or product widget links', function (): void {
    $slot = nearSlot();

    Livewire::withQueryParams(['product' => 'single-session', 'slot' => $slot->id, 'riders' => 3])
        ->test(BookingFlow::class)
        ->assertSet('step', 'riders')
        ->assertCount('guestRiders', 3);

    Livewire::withQueryParams(['slot' => $slot->id])->test(BookingFlow::class)->assertSet('step', 'product');
});

it('ignores prices and amounts sent in the Livewire payload', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $slot = nearSlot();

    $component = Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', $slot->id)
        ->set('riderIds', [$rider->id])
        ->call('confirmRiders');

    // There is no price property to tamper with…
    expect(fn () => $component->set('amountMinor', 1))->toThrow(Exception::class);

    // …and swapping the product slug for another one is re-priced from the database.
    $component->set('productSlug', 'private-session')->call('placeOrder');

    $order = Order::query()->sole();
    expect($order->product_id)->toBe($this->private->id)
        ->and($order->amount_minor)->toBe(1_500_000);
});

it('refuses another customer’s riders smuggled into the payload', function (): void {
    $user = User::factory()->create();
    Rider::factory()->for($user)->create();
    $stranger = Rider::factory()->create();

    Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', nearSlot()->id)
        ->set('riderIds', [$stranger->id])
        ->call('confirmRiders')
        ->assertHasErrors('riderIds')
        ->assertSet('step', 'riders');

    expect(Order::query()->count())->toBe(0);
});

it('sends a guest to sign in at review and resumes the booking afterwards', function (): void {
    Mail::fake();
    $slot = nearSlot();

    Livewire::test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', $slot->id)
        ->set('guestRiders.0.name', 'Zara Malik')
        ->set('guestRiders.0.age_bracket', 'under_12')
        ->call('confirmRiders')
        ->assertSet('step', 'review')
        ->assertSee('Sign in to continue')
        ->set('whatsapp', '0334 4554500')
        ->call('signInToContinue')
        ->assertRedirect(route('login'));

    expect(session(BookingFlow::DRAFT_KEY)['riders'][0]['name'])->toBe('Zara Malik');

    // Sign in with a code; the intended URL brings the customer back.
    $this->post('/login', ['email' => 'zara.parent@example.com']);
    $code = Mail::sent(LoginCodeMail::class)->last()->code;
    $this->post(route('login.verify.store'), ['code' => $code])->assertRedirect(route('book', ['resume' => 1]));

    $user = User::query()->where('email', 'zara.parent@example.com')->sole();

    Livewire::actingAs($user)->withQueryParams(['resume' => 1])->test(BookingFlow::class)
        ->assertSet('step', 'review')
        ->assertSee('Zara Malik')
        ->call('placeOrder');

    expect($user->riders()->sole()->name)->toBe('Zara Malik')
        ->and(Order::query()->sole()->user_id)->toBe($user->id)
        ->and($user->refresh()->whatsapp_phone)->toBe('+923344554500');
});

it('validates guest rider details', function (): void {
    Livewire::test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', nearSlot()->id)
        ->set('guestRiders.0.name', '')
        ->call('confirmRiders')
        ->assertHasErrors(['guestRiders.0.name'])
        ->assertSet('step', 'riders');
});

it('only offers empty slots for a private session', function (): void {
    $busy = nearSlot(3);
    Booking::factory()->create(['slot_id' => $busy->id]);
    $empty = nearSlot(4);

    Livewire::actingAs(User::factory()->create())->test(BookingFlow::class)
        ->call('chooseProduct', 'private-session')
        ->assertSee('Whole slot free')
        ->assertSeeHtml('chooseSlot('.$empty->id.')')
        ->assertDontSeeHtml('chooseSlot('.$busy->id.')');
});

it('sells a package without a date', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', '8-class-package')
        ->assertSee('I’ll choose my dates later')
        ->call('chooseLater')
        ->assertSet('step', 'review')
        ->assertSee('PKR 60,000')
        ->call('placeOrder');

    expect(Order::query()->sole()->amount_minor)->toBe(6_000_000)
        ->and(BookingHold::query()->count())->toBe(0);
});

it('sends the customer back to choose another slot when theirs fills up', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $slot = nearSlot(attributes: ['capacity' => 1]);

    $component = Livewire::actingAs($user)->test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', $slot->id)
        ->set('riderIds', [$rider->id])
        ->call('confirmRiders');

    Booking::factory()->create(['slot_id' => $slot->id]);

    $component->call('placeOrder')->assertSet('step', 'slot')->assertSet('slotId', null);

    expect(Order::query()->count())->toBe(0);
});

it('books with credits from the flow', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    CreditPack::factory()->create(['order_id' => Order::factory()->for($user)->status(OrderStatus::Paid), 'credits_total' => 8, 'credits_used' => 2, 'expires_at' => now()->addWeeks(4)]);
    $slot = nearSlot();

    Livewire::actingAs($user)->test(BookingFlow::class)
        ->assertSee('Use my credits')
        ->assertSee('6 left')
        ->call('chooseProduct', 'credits')
        ->call('chooseSlot', $slot->id)
        ->set('riderIds', [$rider->id])
        ->call('confirmRiders')
        ->assertSee('1 credit')
        ->call('placeOrder')
        ->assertRedirect(route('account.bookings'));

    expect(Booking::query()->sole()->credit_pack_id)->not->toBeNull()
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(1); // only the pack's own order
});

it('shows the checkout summary to the owner only', function (): void {
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $order = app(CreateOrder::class)->handle($user, $this->single, nearSlot()->id, [$rider->id]);

    $this->actingAs($user)->get(route('checkout.show', $order->reference))
        ->assertOk()
        ->assertSee($order->reference)
        ->assertSee('PKR 10,000')
        ->assertSee('Your seats are held.');

    $this->actingAs(User::factory()->create())->get(route('checkout.show', $order->reference))->assertNotFound();
    auth()->logout();
    $this->get(route('checkout.show', $order->reference))->assertRedirect(route('login'));
});
