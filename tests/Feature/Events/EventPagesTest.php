<?php

declare(strict_types=1);

use App\Actions\Booking\FulfilOrder;
use App\Actions\Events\CreateEventOrder;
use App\Actions\Events\SeedCowboyCamp;
use App\Actions\Seo\GenerateSitemap;
use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\BalanceStatus;
use App\Enums\EventQuestionScope;
use App\Enums\EventQuestionType;
use App\Enums\LessonClosure;
use App\Enums\OrderStatus;
use App\Enums\RideEventStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Livewire\Events\EventBooking;
use App\Models\Booking;
use App\Models\Order;
use App\Models\RideEvent;
use App\Models\RideEventCategory;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\OrderConfirmed;
use App\Notifications\WaitlistOffer;
use App\Support\GuestAccess;
use App\Support\ManageLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| Event pages, booking, manage page and run sheet (decided 2026-11-22).
| Helpers are prefixed `ep` (test files share one namespace).
*/

function epCamp(array $attributes = []): RideEvent
{
    $event = RideEvent::factory()->published()->deposit(50)->create([
        'slug' => 'cowboy-camp',
        'title' => 'Cowboy Camp',
        'summary' => 'A full day as a ranch hand.',
        'acknowledgement_text' => 'I’ll wear a cowboy hat and western wear.',
        'blocks' => [
            ['type' => 'programme', 'data' => ['items' => [['time' => '10:00', 'activity' => 'Arrival and meeting the herd'], ['time' => '2:30', 'activity' => 'The outride (45 minutes)']]]],
            ['type' => 'included', 'data' => ['included' => ['Ranch lunch'], 'not_included' => ['Transport']]],
            ['type' => 'dress_code', 'data' => ['text' => 'No hat, no ride.', 'items' => ['Cowboy hat and western wear']]],
            ['type' => 'faq', 'data' => ['items' => [['question' => 'Is lunch included?', 'answer' => 'Yes, a ranch lunch.']]]],
        ],
        'questions' => [
            ['key' => 'exp', 'label' => 'Riding experience', 'type' => EventQuestionType::Select->value, 'options' => ['Never', 'Some'], 'required' => true, 'scope' => EventQuestionScope::Attendee->value],
        ],
        ...$attributes,
    ]);

    return $event;
}

/** @return array{name: string, email: string, email_confirmation: string, whatsapp: string, draft: string} */
function epGuestForm(string $email = 'rider@example.com'): array
{
    return [
        'name' => 'Sana Ali',
        'email' => $email,
        'email_confirmation' => $email,
        'whatsapp' => '0334 4554500',
        'draft' => hash_hmac('sha256', (string) json_encode(session(EventBooking::DRAFT_KEY)), (string) config('app.key')),
    ];
}

it('lists upcoming events with category filters and hides past and draft ones', function (): void {
    // Seeded by the events migration.
    $camp = RideEventCategory::query()->where('slug', 'camp')->firstOrFail();
    $trip = RideEventCategory::query()->where('slug', 'trip')->firstOrFail();
    $event = epCamp(['ride_event_category_id' => $camp->id]);
    eventDate($event, capacity: 2);
    $other = RideEvent::factory()->published()->create(['title' => 'Mountain Trip', 'ride_event_category_id' => $trip->id]);
    eventDate($other);
    $past = RideEvent::factory()->published()->create(['title' => 'Old Ride']);
    Slot::factory()->forEvent($past)->at(CarbonImmutable::now('Asia/Karachi')->subDays(3)->setTime(10, 0))->create();
    $draft = RideEvent::factory()->create(['title' => 'Secret Draft']);
    eventDate($draft);

    $this->get('/events')->assertOk()
        ->assertSee('Cowboy Camp')->assertSee('Mountain Trip')
        ->assertDontSee('Old Ride')->assertDontSee('Secret Draft')
        ->assertSee('Few spots left')
        ->assertSee('Camps')->assertSee('Trips');

    $this->get('/events?category=trip')->assertOk()->assertSee('Mountain Trip')->assertDontSee('Cowboy Camp');
    $this->get('/events/secret-draft-x')->assertNotFound();
    $this->get('/events/'.$draft->slug)->assertNotFound();
});

it('shows the event page with the sticky box, dates, programme, dress code, rules and Event data', function (): void {
    $event = epCamp();
    $sat = eventDate($event, days: 20, capacity: 10);
    $sun = eventDate($event, days: 21, capacity: 2);

    $page = $this->get('/events/cowboy-camp')->assertOk()
        ->assertSee('Cowboy Camp')
        ->assertSee('PKR 10,000')
        ->assertSee('PKR 5,000 deposit')
        ->assertSee($sat->dateRangeLabel())
        ->assertSee($sun->dateRangeLabel())
        ->assertSee('Selling fast')
        ->assertSee('Arrival and meeting the herd')
        ->assertSee('Ranch lunch')
        ->assertSee('No hat, no ride.')
        ->assertSee('deposit confirms your place and is non-refundable')
        ->assertSee('If we cancel a date')
        ->assertDontSee('2 seats')
        ->assertSee(route('events.book', ['event' => 'cowboy-camp', 'date' => $sat->id]), false);

    $types = jsonLdTypes(jsonLdGraph($page->getContent()));
    $events = collect(jsonLdGraph($page->getContent()))->where('@type', 'Event')->values();

    expect($types)->toContain('Event', 'FAQPage', 'BreadcrumbList')
        ->and($events)->toHaveCount(2)
        ->and($events[0]['startDate'])->toContain('+05:00')
        ->and($events[0]['offers'][0]['priceCurrency'])->toBe('PKR')
        ->and($events[0]['offers'][0]['price'])->toBe('10000.00')
        ->and($events[0]['eventAttendanceMode'])->toBe('https://schema.org/OfflineEventAttendanceMode')
        ->and($events[1]['offers'][0]['availability'])->toBe('https://schema.org/LimitedAvailability');
});

it('puts events on the home page, /experiences, the sitemap and llms.txt', function (): void {
    $this->seed();
    $event = epCamp();
    eventDate($event);

    $this->get('/')->assertOk()->assertSee('Upcoming events.')->assertSee('Cowboy Camp');
    $this->get('/experiences')->assertOk()->assertSee('Cowboy Camp')->assertSee('Events');
    $this->get('/experiences?category=events')->assertOk()->assertSee('Cowboy Camp')->assertDontSee('Single session');
    $this->get('/llms.txt')->assertOk()->assertSee('Cowboy Camp')->assertSee('50% non-refundable deposit');

    app(GenerateSitemap::class)->handle();
    expect(file_get_contents((string) config('site.sitemap_path')))->toContain(route('events.show', $event))->toContain(route('events.index'));
});

it('lets a guest book on the event form, give their details and reach checkout with the deposit to pay', function (): void {
    Notification::fake();
    $event = epCamp();
    $date = eventDate($event);
    $tier = $event->tiers()->firstOrFail();

    Livewire::test(EventBooking::class, ['event' => $event])
        ->call('choose', $date->id)
        ->call('add', $tier->id)
        ->set('people.0.name', 'Sana Ali')
        ->set('people.0.answers.exp', 'Never')
        ->set('people.1.name', 'Bilal Ali')
        ->set('people.1.answers.exp', 'Some')
        ->set('acknowledged', true)
        ->assertSee('PKR 20,000')
        ->assertSee('PKR 10,000')
        ->call('continueAsGuest')
        ->assertRedirect(route('events.details', ['event' => 'cowboy-camp']));

    $this->get(route('events.details', ['event' => 'cowboy-camp']))->assertOk()
        ->assertSee('Sana Ali, Bilal Ali')
        ->assertSee('Deposit to pay now');

    $response = $this->post(route('events.details.store', ['event' => 'cowboy-camp']), epGuestForm());
    $order = Order::query()->sole();
    $response->assertRedirect(route('checkout.show', $order->reference));

    expect($order->placed_as_guest)->toBeTrue()
        ->and($order->amount_minor)->toBe(1_000_000)
        ->and($order->balance_minor)->toBe(1_000_000)
        ->and($order->items()->count())->toBe(2)
        ->and(app(GuestAccess::class)->allowsOrder($order))->toBeTrue();

    $this->get(route('checkout.show', $order->reference))->assertOk()
        ->assertSee('Deposit to pay now')
        ->assertSee('Balance')
        ->assertSee('Cancellations')
        ->assertSee('deposit confirms your place and is non-refundable');
});

it('keeps the guest on the form when the checkbox isn’t ticked', function (): void {
    $event = epCamp();
    $date = eventDate($event);

    session()->put(EventBooking::DRAFT_KEY, ['event' => 'cowboy-camp', 'date' => $date->id, 'people' => [['tier_id' => $event->tiers()->value('id'), 'name' => 'Sana', 'answers' => ['exp' => 'Never']]], 'answers' => [], 'acknowledged' => false]);

    $this->post(route('events.details.store', ['event' => 'cowboy-camp']), epGuestForm())
        ->assertRedirect(route('events.book', ['event' => 'cowboy-camp', 'resume' => 1]))
        ->assertSessionHas('booking_notice');

    expect(Order::query()->count())->toBe(0);
});

it('books a signed-in customer straight to checkout', function (): void {
    $event = epCamp(['acknowledgement_text' => null, 'questions' => null]);
    $date = eventDate($event);
    $user = User::factory()->create(['whatsapp_phone' => '+923001234567']);
    $this->actingAs($user);

    Livewire::test(EventBooking::class, ['event' => $event])
        ->call('choose', $date->id)
        ->set('people.0.name', 'Me Myself')
        ->call('book')
        ->assertRedirect(route('checkout.show', Order::query()->sole()->reference));
});

it('emails a confirmation with the deposit, balance, dress code and rules, and shows them on the manage page', function (): void {
    $event = epCamp();
    $date = eventDate($event);
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, [['tier_id' => $event->tiers()->value('id'), 'name' => 'Sana', 'answers' => ['exp' => 'Never']]], [], true);
    app(FulfilOrder::class)->handle($order);
    $order->refresh();

    $mail = (new OrderConfirmed($order))->toMail($order->user);
    $html = (string) $mail->render();

    expect($html)->toContain('Deposit paid')
        ->toContain('Balance PKR 5,000')
        ->toContain('No hat, no ride.')
        ->toContain('I’ll wear a cowboy hat and western wear.')
        ->toContain('deposit confirms your place and is non-refundable');

    $this->get(ManageLink::forOrder($order))->assertRedirect(route('manage.show', $order->reference));
    $this->get(route('manage.show', $order->reference))->assertOk()
        ->assertSee('Deposit paid')
        ->assertSee('Pay the balance by card')
        ->assertSee('Cancel this booking');

    $this->get(route('manage.event.cancel', $order->reference))->assertOk()->assertSee('There is no refund.');
    $this->post(route('manage.event.cancel.store', $order->reference))->assertRedirect(route('manage.show', $order->reference));

    expect(Booking::query()->where('order_id', $order->id)->value('status')->value)->toBe('cancelled')
        ->and($order->balance()->value('status'))->toBe(BalanceStatus::Void);
});

it('shows the run sheet with tiers, answers and balance, marks the balance paid and exports CSV', function (): void {
    Notification::fake();
    $event = epCamp();
    $date = eventDate($event);
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, [['tier_id' => $event->tiers()->value('id'), 'name' => 'Sana Ali', 'answers' => ['exp' => 'Never']]], [], true);
    app(FulfilOrder::class)->handle($order);
    $booking = Booking::query()->where('order_id', $order->id)->sole();

    $this->actingAs(User::factory()->trainer()->withMfa()->create());
    $this->get(route('team.sessions.show', $date))->assertForbidden();

    $this->actingAs(User::factory()->staff()->withMfa()->create());
    $this->get(route('team.sessions.show', $date))->assertOk()
        ->assertSee('Cowboy Camp')->assertSee('Sana Ali')->assertSee('Riding experience')->assertSee('Never')
        ->assertSee('Deposit paid, balance due')
        ->assertDontSee('Mark balance paid')
        ->assertDontSee('PKR 5,000');
    $this->get(route('team.sessions.export', $date))->assertForbidden();

    $this->actingAs(User::factory()->admin()->withMfa()->create());
    $this->get(route('team.sessions.show', $date))->assertOk()->assertSee('Mark balance paid')->assertSee('PKR 5,000');

    $this->post(route('team.sessions.balance', [$date, $booking]), ['method' => 'cash'])->assertRedirect();
    expect($order->balance()->value('status'))->toBe(BalanceStatus::Paid);

    $csv = $this->get(route('team.sessions.export', $date))->assertOk()->streamedContent();
    expect($csv)->toContain('Riding experience')->toContain('Sana Ali')->toContain('Never')->toContain('Balance paid');
});

it('seeds Cowboy Camp once, published, with both dates closing the day’s lessons', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-22 09:00', 'Asia/Karachi'));
    $lesson = Slot::factory()->at(CarbonImmutable::parse('2026-12-19 15:00', 'Asia/Karachi'))->create();
    $booked = Slot::factory()->at(CarbonImmutable::parse('2026-12-20 08:00', 'Asia/Karachi'))->create();
    $keep = Booking::factory()->create(['slot_id' => $booked->id]);
    Notification::fake();

    $event = app(SeedCowboyCamp::class)->handle();

    expect($event)->not->toBeNull()
        ->and(app(SeedCowboyCamp::class)->handle())->toBeNull()
        ->and($event->status)->toBe(RideEventStatus::Published)
        ->and($event->deposit_percent)->toBe(50)
        ->and($event->tiers()->value('price_minor'))->toBe(1_000_000)
        ->and($event->occurrences()->count())->toBe(2)
        ->and($event->occurrences()->pluck('capacity')->all())->toBe([10, 10])
        ->and($event->occurrences()->first()->lesson_closure)->toBe(LessonClosure::Day)
        ->and($event->occurrences()->first()->localStartsAt()->format('Y-m-d H:i'))->toBe('2026-12-19 10:00')
        ->and($event->occurrences()->first()->localEndsAt()->format('H:i'))->toBe('17:00')
        ->and(count($event->blocksOf('programme')[0]['items']))->toBe(9)
        ->and($lesson->refresh()->status)->toBe(SlotStatus::Closed)
        ->and($booked->refresh()->status)->toBe(SlotStatus::Closed)
        ->and($keep->refresh()->status->value)->toBe('confirmed');

    $this->get('/events/cowboy-camp')->assertOk()->assertSee('No hat, no ride.')->assertSee('Golden-hour farewell');
});

it('keeps an unpaid event order out of the paid lists until it is paid', function (): void {
    $event = epCamp();
    $order = app(CreateEventOrder::class)->handle(User::factory()->create(), $event, eventDate($event)->id, [['tier_id' => $event->tiers()->value('id'), 'name' => 'A', 'answers' => ['exp' => 'Some']]], [], true);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->balance()->exists())->toBeFalse();
});

it('offers a waitlist on a full date only when the event has one, and the offer books on the event form', function (): void {
    $event = epCamp(['waitlist_enabled' => false]);
    $date = eventDate($event, capacity: 1);
    app(CreateEventOrder::class)->handle(User::factory()->create(), $event, $date->id, [['tier_id' => $event->tiers()->value('id'), 'name' => 'First', 'answers' => ['exp' => 'Some']]], [], true);
    $customer = User::factory()->create();

    expect(eventRejection(fn () => app(JoinWaitlist::class)->handle($customer, $date->id, 1)))->toBe(BookingRejected::NOT_ALLOWED);

    $event->update(['waitlist_enabled' => true]);
    $this->get('/events/cowboy-camp')->assertOk()->assertSee('Join the waitlist');

    $entry = app(JoinWaitlist::class)->handle($customer, $date->id, 1);

    expect(WaitlistOffer::bookUrl($entry->refresh()))->toBe(route('events.book', ['event' => 'cowboy-camp', 'date' => $date->id]));
});
