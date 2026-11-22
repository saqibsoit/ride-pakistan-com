<?php

declare(strict_types=1);

use App\Enums\BalanceStatus;
use App\Enums\BookingStatus;
use App\Enums\EventQuestionScope;
use App\Enums\EventQuestionType;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\RideEvent;
use Illuminate\Support\Facades\Notification;

/*
 * Booking an event on a phone (decided 2026-11-22): a signed-out visitor
 * opens Cowboy Camp, taps Book in the bottom bar, picks a date, names the
 * rider, answers the question, ticks the dress code, gives their details
 * and pays the deposit by card. Each step waits for its own result (see
 * tests/Pest.php). Helpers are prefixed `eb`.
 */

beforeEach(function (): void {
    Notification::fake();
});

it('books Cowboy Camp as a guest on a phone and pays the deposit by card', function (): void {
    $event = RideEvent::factory()->published()->deposit(50)->create([
        'slug' => 'cowboy-camp',
        'title' => 'Cowboy Camp',
        'summary' => 'A full day as a ranch hand.',
        'acknowledgement_text' => 'I’ll wear a cowboy hat and western wear.',
        'blocks' => [['type' => 'dress_code', 'data' => ['text' => 'No hat, no ride.', 'items' => []]]],
        'questions' => [['key' => 'exp', 'label' => 'Riding experience', 'type' => EventQuestionType::Select->value, 'options' => ['Never ridden', 'Confident'], 'required' => true, 'scope' => EventQuestionScope::Attendee->value]],
    ]);
    $date = eventDate($event, days: 20);

    $page = visit('/events/cowboy-camp')->on()->mobile()->assertSee('No hat, no ride.');
    $browser = $page->page();
    $page->assertNoJavaScriptErrors();

    // The mobile bottom bar.
    $browser->locator('.bottom-bar a')->first()->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, '1. Choose a date.');
    waitForLivewire($browser);

    $browser->locator('input[type=radio][name=date][value="'.$date->id.'"]')->click(['timeout' => E2E_WAIT_MS]);
    fillOnce($browser, 'Full name', 'Sana Ali');
    $browser->getByLabel('Riding experience', true)->first()->selectOption('Never ridden', ['timeout' => E2E_WAIT_MS]);
    $browser->getByText('I’ll wear a cowboy hat and western wear.')->first()->click(['timeout' => E2E_WAIT_MS]);
    waitForText($browser, 'Deposit to pay now');

    pressOnce($browser, 'Continue as guest');
    waitForText($browser, 'Your details.');
    waitForText($browser, 'Sana Ali');

    fillOnce($browser, 'Your name', 'Sana Ali');
    fillOnce($browser, 'Email', 'sana@example.com');
    fillOnce($browser, 'Email again', 'sana@example.com');
    fillOnce($browser, 'WhatsApp number', '0334 4554500');
    pressOnce($browser, 'Continue to payment');
    waitForText($browser, 'How would you like to pay?');
    waitForText($browser, 'Deposit to pay now');

    $order = Order::query()->sole();

    expect($order->placed_as_guest)->toBeTrue()
        ->and($order->amount_minor)->toBe(500_000)
        ->and($order->balance_minor)->toBe(500_000)
        ->and($order->items()->sole()->answers)->toBe(['exp' => 'Never ridden']);

    payByCardInBrowser($this, $browser, $order);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(Booking::query()->where('order_id', $order->id)->sole()->status)->toBe(BookingStatus::Confirmed)
        ->and($order->balance->status)->toBe(BalanceStatus::Due);
});
