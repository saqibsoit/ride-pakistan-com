<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Discounts\PromoEligibility;
use App\Enums\AccountCreditReason;
use App\Enums\OrderStatus;
use App\Exceptions\GuestCheckoutRejected;
use App\Http\Controllers\Public\GuestBookingController;
use App\Livewire\Book\BookingFlow;
use App\Models\AccountCreditEntry;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\TransferInstructions;
use App\Support\GuestAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| Guest checkout (decided 2026-11-12): booking and paying without signing
| in, and never learning anything about an account that already uses the
| email. Helpers are prefixed `gc` (test files share one namespace).
*/

beforeEach(function (): void {
    Notification::fake();
    $this->single = Product::factory()->create(['slug' => 'single-session', 'name' => 'Single session', 'price_minor' => 1_000_000]);
    $this->slot = nearSlot(4);
});

/** A guest draft in the session, as BookingFlow::continueAsGuest leaves it. */
function gcDraft(Slot $slot, array $riders = ['Zara Malik'], string $product = 'single-session'): void
{
    session()->put(BookingFlow::DRAFT_KEY, [
        'whatsapp' => '',
        'product' => $product,
        'use' => '',
        'slot' => $slot->id,
        'later' => false,
        'riders' => array_map(fn (string $name): array => ['name' => $name, 'age_bracket' => '', 'experience_level' => ''], $riders),
        'request' => null,
    ]);
}

function gcHash(): string
{
    return hash_hmac('sha256', (string) json_encode(session(BookingFlow::DRAFT_KEY)), (string) config('app.key'));
}

/** @return array<string, string> */
function gcForm(string $email = 'guest@example.com', array $overrides = []): array
{
    return [
        'name' => 'Ayesha Khan',
        'email' => $email,
        'email_confirmation' => $email,
        'whatsapp' => '0334 4554500',
        'draft' => gcHash(),
        ...$overrides,
    ];
}

it('takes a guest from the review step to the details page', function (): void {
    Livewire::test(BookingFlow::class)
        ->call('chooseProduct', 'single-session')
        ->call('chooseSlot', $this->slot->id)
        ->set('guestRiders.0.name', 'Zara Malik')
        ->call('confirmRiders')
        ->assertSee('Continue as guest')
        ->call('continueAsGuest')
        ->assertRedirect(route('book.details'));

    $this->get(route('book.details'))
        ->assertOk()
        ->assertSee('Your details.')
        ->assertSee('Zara Malik')
        ->assertSee($this->slot->label())
        ->assertSee('PKR 10,000');
});

it('places a guest order, opens its checkout and pays by card', function (): void {
    gcDraft($this->slot, ['Zara Malik', 'Omar Malik']);

    $response = $this->post(route('book.details.store'), gcForm());

    $order = Order::query()->sole();
    $response->assertRedirect(route('checkout.show', $order->reference));

    expect($order->placed_as_guest)->toBeTrue()
        ->and($order->contact_name)->toBe('Ayesha Khan')
        ->and($order->contact_whatsapp)->toBe('+923344554500')
        ->and($order->user->email)->toBe('guest@example.com')
        ->and($order->user->email_verified_at)->toBeNull()
        ->and($order->amount_minor)->toBe(2_000_000)
        ->and(BookingHold::query()->where('order_id', $order->id)->sum('seats'))->toEqual(2)
        ->and(session(BookingFlow::DRAFT_KEY))->toBeNull();

    $this->get(route('checkout.show', $order->reference))
        ->assertOk()
        ->assertSee('Booking as a guest')
        ->assertSee('guest@example.com')
        ->assertDontSee('account credit');

    $this->post(route('checkout.card', $order->reference))->assertRedirect();
    completeCardPayment($this, $order);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);

    $this->get(route('checkout.status', $order->reference))
        ->assertOk()
        ->assertSee('Manage this booking')
        ->assertDontSee(route('account.bookings'));
});

it('refuses mismatched emails and keeps nothing', function (): void {
    gcDraft($this->slot);

    $this->post(route('book.details.store'), gcForm('one@example.com', ['email_confirmation' => 'two@example.com']))
        ->assertSessionHasErrors(['email' => 'The two email addresses don’t match.']);

    expect(Order::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
});

it('refuses a failed security check and keeps nothing', function (): void {
    config(['services.turnstile.secret_key' => 'secret', 'services.turnstile.site_key' => 'site']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
    gcDraft($this->slot);

    $this->get(route('book.details'))->assertSee('cf-turnstile', false);

    $this->post(route('book.details.store'), gcForm())
        ->assertSessionHasErrors(['email' => GuestCheckoutRejected::VERIFICATION]);

    expect(Order::query()->count())->toBe(0)->and(User::query()->count())->toBe(0);
});

it('limits guest bookings per email', function (): void {
    foreach (range(1, GuestBookingController::EMAIL_LIMIT_PER_HOUR) as $i) {
        gcDraft(nearSlot(4 + $i));
        $this->post(route('book.details.store'), gcForm('busy@example.com'))->assertSessionHasNoErrors();
        $this->travel(61)->seconds(); // past the per-minute IP limit, well inside the hour
    }

    gcDraft(nearSlot(20));
    $this->post(route('book.details.store'), gcForm('busy@example.com'))
        ->assertSessionHasErrors(['email' => GuestCheckoutRejected::TOO_MANY]);

    expect(Order::query()->count())->toBe(GuestBookingController::EMAIL_LIMIT_PER_HOUR);
});

it('books onto an existing account without showing or changing anything of it', function (): void {
    $existing = User::factory()->create(['email' => 'sana@example.com', 'name' => 'Sana Secret-Name', 'whatsapp_phone' => '+923339998877']);
    Rider::factory()->for($existing)->create(['name' => 'Secret Rider']);
    AccountCreditEntry::query()->create(['user_id' => $existing->id, 'delta_minor' => 200_000, 'reason' => AccountCreditReason::Adjusted, 'note' => 'Test']);
    CreditPack::factory()->create(['order_id' => Order::factory()->for($existing)->status(OrderStatus::Paid)->create(['reference' => 'RP-SECRET'])->id]);

    // The account also holds an order for this very booking: it must not be handed out.
    $held = app(CreateOrder::class)->handle($existing, $this->single, $this->slot->id, [$existing->riders()->value('id')]);

    gcDraft($this->slot, ['Secret Rider']);
    $this->post(route('book.details.store'), gcForm('SANA@example.com'))->assertSessionHasNoErrors();

    $order = Order::query()->where('placed_as_guest', true)->sole();
    expect($order->id)->not->toBe($held->id)
        ->and($order->user_id)->toBe($existing->id)
        ->and($order->contact_name)->toBe('Ayesha Khan');

    $existing->refresh();
    expect($existing->name)->toBe('Sana Secret-Name')
        ->and($existing->whatsapp_phone)->toBe('+923339998877')
        ->and($existing->riders()->count())->toBe(2); // the guest's "Secret Rider" is a new rider

    $page = $this->get(route('checkout.show', $order->reference))->assertOk();
    foreach (['Sana Secret-Name', 'RP-SECRET', $held->reference, 'account credit', '2,000'] as $secret) {
        $page->assertDontSee($secret);
    }

    // The held order stays the account's own; this browser can't open it.
    $this->get(route('checkout.show', $held->reference))->assertRedirect(route('login'));
});

it('answers a new and an existing email the same way', function (): void {
    User::factory()->create(['email' => 'known@example.com']);

    gcDraft($this->slot);
    $known = $this->post(route('book.details.store'), gcForm('known@example.com'));
    gcDraft(nearSlot(5));
    $fresh = $this->post(route('book.details.store'), gcForm('fresh@example.com'));

    $checkout = url('/checkout/RP-');
    expect($known->status())->toBe($fresh->status())
        ->and($known->getSession()?->get('errors'))->toBeNull()
        ->and(str_starts_with((string) $known->headers->get('Location'), $checkout))->toBeTrue()
        ->and(str_starts_with((string) $fresh->headers->get('Location'), $checkout))->toBeTrue();
});

it('refuses team, deleted and anonymised accounts with one neutral message', function (string $state): void {
    $user = match ($state) {
        'admin' => User::factory()->admin()->withMfa()->create(),
        'staff' => User::factory()->staff()->withMfa()->create(),
        'deleted' => User::factory()->deletedDaysAgo(2)->create(),
        'anonymised' => tap(User::factory()->create(), fn (User $u) => $u->forceFill(['anonymised_at' => now()])->save()),
    };

    gcDraft($this->slot);
    $this->post(route('book.details.store'), gcForm($user->email))
        ->assertSessionHasErrors(['email' => GuestCheckoutRejected::EMAIL]);

    expect(Order::query()->count())->toBe(0)
        ->and(Rider::query()->count())->toBe(0);
})->with(['admin', 'staff', 'deleted', 'anonymised']);

it('keeps a guest order to the browser that placed it', function (): void {
    gcDraft($this->slot);
    $this->post(route('book.details.store'), gcForm());
    $order = Order::query()->sole();

    // Another visitor (fresh session): the sign-in page, as for any reference.
    $this->flushSession();
    $this->get(route('checkout.show', $order->reference))->assertRedirect(route('login'));
    $this->get(route('checkout.show', 'RP-NOSUCH'))->assertRedirect(route('login'));

    // Someone else signed in: 404.
    $this->actingAs(User::factory()->create())->get(route('checkout.show', $order->reference))->assertNotFound();
});

it('sends a guest back to the slot step when the slot filled up meanwhile', function (): void {
    gcDraft($this->slot);
    $this->slot->update(['capacity' => 1]);
    $other = User::factory()->create();
    app(CreateOrder::class)->handle($other, $this->single, $this->slot->id, [Rider::factory()->for($other)->create()->id]);

    $this->post(route('book.details.store'), gcForm())->assertRedirect(route('book', ['resume' => 1]));

    expect(Order::query()->where('placed_as_guest', true)->count())->toBe(0)
        ->and(User::query()->where('email', 'guest@example.com')->exists())->toBeFalse();
});

it('notices a draft changed in another tab', function (): void {
    gcDraft($this->slot);
    $form = gcForm();
    gcDraft(nearSlot(6), ['Someone Else']);

    $this->post(route('book.details.store'), $form)->assertRedirect(route('book', ['resume' => 1]));
    expect(Order::query()->count())->toBe(0);
});

it('asks guests to sign in for history-based promo codes and never offers account credit', function (): void {
    gcDraft($this->slot);
    $this->post(route('book.details.store'), gcForm());
    $order = Order::query()->sole();

    $firstOnly = PromoCode::factory()->create(['code' => 'FIRSTRIDE', 'first_booking_only' => true]);
    $perCustomer = PromoCode::factory()->create(['code' => 'ONCEEACH', 'max_uses_per_customer' => 1]);
    $plain = PromoCode::factory()->fixed(100_000)->create(['code' => 'SPRING']);

    foreach ([$firstOnly, $perCustomer] as $promo) {
        $this->post(route('checkout.discount', $order->reference), ['code' => $promo->code])
            ->assertSessionHasErrors(['code' => PromoEligibility::SIGN_IN]);
    }

    $this->post(route('checkout.discount', $order->reference), ['code' => $plain->code])->assertSessionHasNoErrors();
    expect($order->refresh()->amount_minor)->toBe(900_000);

    $this->post(route('checkout.credit', $order->reference), ['use' => 1])
        ->assertSessionHasErrors(['code' => 'Sign in to use your account credit.']);
});

it('emails a guest the bank details and their manage link once when they choose bank transfer', function (): void {
    $this->slot->update(['starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(2)]);
    gcDraft($this->slot);
    $this->post(route('book.details.store'), gcForm());
    $order = Order::query()->sole();

    $this->post(route('checkout.transfer.choose', $order->reference))->assertRedirect(route('checkout.transfer', $order->reference));
    $this->post(route('checkout.transfer.choose', $order->reference));

    Notification::assertSentToTimes($order->user, TransferInstructions::class, 1);
    $this->get(route('checkout.transfer', $order->reference))->assertOk()->assertSee($order->reference);
});

it('grants guest access only for the current link version', function (): void {
    gcDraft($this->slot);
    $this->post(route('book.details.store'), gcForm());
    $order = Order::query()->sole();
    $access = app(GuestAccess::class);

    expect($access->allowsOrder($order))->toBeTrue();

    $order->forceFill(['manage_link_version' => 2])->save();

    expect($access->allowsOrder($order->refresh()))->toBeFalse();
    $this->get(route('checkout.show', $order->reference))->assertRedirect(route('login'));
});
