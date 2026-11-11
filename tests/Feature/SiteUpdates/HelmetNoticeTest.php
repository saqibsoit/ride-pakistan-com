<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Settings\UpdateSettings;
use App\Enums\SettingKey;
use App\Models\Faq;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Notifications\OrderConfirmed;
use App\Notifications\SessionReminder;
use Carbon\CarbonImmutable;

/*
 * Prompt 14, item 5: riders bring their own helmet. One setting
 * (policies.helmet_notice) feeds the session pages' "What to bring", the
 * FAQ ({helmet}), the booking confirmations and the 24-hour reminder.
 */

const HELMET = 'Please bring your own riding helmet. Riders without a helmet can’t ride.';

function helmetPaidOrder(): Order
{
    $user = User::factory()->create();
    $rider = Rider::factory()->for($user)->create();
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create(['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(['price_minor' => 1_000_000]), $slot->id, [$rider->id]);

    return app(FulfilOrder::class)->handle($order)->refresh();
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

it('opens "What to bring" with the helmet notice on every session page', function (string $slug): void {
    $this->seed();

    $html = (string) $this->get('/sessions/'.$slug)->assertOk()->getContent();

    expect($html)->toContain('data-helmet-notice')
        ->and(strpos($html, 'Please bring your own riding helmet'))->toBeGreaterThan(strpos($html, 'What to bring.'));
})->with(['single-session', 'private-session', '8-class-package', '16-class-package']);

it('answers "What should I wear?" with the helmet notice', function (): void {
    $this->seed();

    expect(Faq::query()->where('question', 'What should I wear?')->value('answer'))->toEndWith('{helmet}');

    $this->get('/faq')->assertOk()->assertSee(HELMET);
});

it('adds the token to an existing FAQ once, and leaves one that already mentions helmets', function (): void {
    $migration = require database_path('migrations/2026_11_20_100100_add_helmet_token_to_wear_faq.php');
    $faq = Faq::query()->create(['category' => 'Getting started', 'question' => 'What should I wear?', 'answer' => 'Long trousers.', 'is_published' => true]);

    $migration->up();
    $migration->up();
    expect($faq->refresh()->answer)->toBe('Long trousers. {helmet}');

    $faq->forceFill(['answer' => 'Bring a helmet and long trousers.'])->save();
    $migration->up();
    expect($faq->refresh()->answer)->toBe('Bring a helmet and long trousers.');
});

it('is in the booking confirmations and the reminder', function (): void {
    $order = helmetPaidOrder();
    $bookings = $order->bookings()->get();

    $emails = [
        (string) (new OrderConfirmed($order))->toMail($order->user)->render(),
        (string) (new BookingConfirmed($bookings))->toMail($order->user)->render(),
        (string) (new SessionReminder($bookings->first()->slot, $bookings))->toMail($order->user)->render(),
    ];

    foreach ($emails as $html) {
        expect($html)->toContain('Before you come')->toContain('Please bring your own riding helmet. Riders without a helmet can’t ride.');
    }
});

it('follows the setting', function (): void {
    $this->seed();
    app(UpdateSettings::class)->handle([SettingKey::HelmetNotice->value => 'Bring a riding helmet; no helmet, no ride.']);

    $this->get('/sessions/single-session')->assertSee('Bring a riding helmet; no helmet, no ride.')->assertDontSee(HELMET);
    $this->get('/faq')->assertSee('Bring a riding helmet; no helmet, no ride.');
});
