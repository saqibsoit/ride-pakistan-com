<?php

declare(strict_types=1);

use App\Actions\Booking\CreateOrder;
use App\Actions\Booking\FulfilOrder;
use App\Actions\Notifications\ResendNotification;
use App\Actions\Notifications\SendCreditExpiryWarnings;
use App\Actions\Notifications\SendSessionReminders;
use App\Actions\Notifications\SendThankYous;
use App\Actions\Settings\UpdateSettings;
use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\BookingStatus;
use App\Enums\CreditReason;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\CreditsExpiringSoon;
use App\Notifications\OrderConfirmed;
use App\Notifications\SessionReminder;
use App\Notifications\SlotCancelledNotice;
use App\Notifications\ThankYou;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;

const SECRET_PIN = 'https://maps.example.com/?q=33.70,73.05-secret-pin';

function pkt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Karachi');
}

/** A paid single-session order for $riders riders in a slot at $local. */
function paidOrderAt(string $local, int $riders = 1, ?User $user = null): Order
{
    $user ??= User::factory()->create();
    $riderIds = array_values(array_map('intval', Rider::factory()->count($riders)->for($user)->create()->modelKeys()));
    $slot = Slot::factory()->at(pkt($local))->create(['capacity' => 6]);
    $order = app(CreateOrder::class)->handle($user, Product::factory()->create(['price_minor' => 1_000_000]), $slot->id, $riderIds);

    return app(FulfilOrder::class)->handle($order)->refresh();
}

beforeEach(function (): void {
    // The slots below sit on fixed dates, so freeze the clock before them.
    $this->travelTo(pkt('2026-11-10 12:00'));
    app(UpdateSettings::class)->handle([SettingKey::MapPinUrl->value => SECRET_PIN]);
});

it('sends the booking confirmation with tickets, QR codes, Pakistan times and the map pin', function (): void {
    $this->travelTo(pkt('2026-11-10 12:00'));
    $order = paidOrderAt('2026-11-14 08:00', riders: 2);

    $mail = (new OrderConfirmed($order))->toMail($order->user);
    $html = (string) $mail->render();
    $codes = $order->bookings()->pluck('ticket_code')->all();

    expect($html)->toContain(SECRET_PIN)
        ->toContain('Sat 14 Nov 2026, 08:00')
        ->toContain($codes[0])->toContain($codes[1])
        ->and($mail->rawAttachments)->toHaveCount(3) // two tickets and the .ics
        ->and($mail->rawAttachments[0]['name'])->toStartWith('ticket-')
        ->and($mail->rawAttachments[0]['options']['mime'])->toBe('image/png');
});

it('sends a real email with inline QR images and a plain-text part', function (): void {
    $order = paidOrderAt('2026-11-20 08:00');

    $order->user->notifyNow(new OrderConfirmed($order));

    /** @var SentMessage $sent */
    $sent = app('mailer')->getSymfonyTransport()->messages()->last();
    $email = $sent->getOriginalMessage();

    expect($email->getTextBody())->toContain($order->reference)
        ->and($email->getHtmlBody())->toContain('cid:')
        ->and(collect($email->getAttachments())->filter(fn ($a) => $a->getMediaType() === 'image')->count())->toBeGreaterThanOrEqual(2);
});

it('says the transfer was approved when a bank transfer order is confirmed', function (): void {
    $order = paidOrderAt('2026-11-20 08:00');
    $order->payment_method = PaymentMethod::BankTransfer;
    $order->save();

    expect((string) (new OrderConfirmed($order))->toMail($order->user)->render())->toContain('bank transfer for order')->toContain('approved');
});

it('never shows the map pin on public pages', function (): void {
    $this->seed();
    app(UpdateSettings::class)->handle([SettingKey::MapPinUrl->value => SECRET_PIN]);

    foreach (['/', '/experiences', '/schedule', '/prices', '/faq', '/contact', '/book', '/llms.txt', '/sessions/single-session', '/trainer/omar-saleem'] as $url) {
        $this->get($url)->assertOk()->assertDontSee(SECRET_PIN, false)->assertDontSee('secret-pin', false);
    }
});

it('sends one reminder about 24 hours before, in Pakistan time, and never twice', function (): void {
    Notification::fake();
    $this->travelTo(pkt('2026-11-10 09:00'));
    $order = paidOrderAt('2026-11-14 08:00', riders: 2); // Saturday 08:00
    $cancelled = paidOrderAt('2026-11-14 15:00');
    $cancelled->bookings()->update(['status' => BookingStatus::Cancelled]);

    $this->travelTo(pkt('2026-11-13 06:50')); // 25 h 10 min before
    expect(app(SendSessionReminders::class)->handle())->toBe(0);

    $this->travelTo(pkt('2026-11-13 07:05')); // inside the window
    expect(app(SendSessionReminders::class)->handle())->toBe(1);

    $this->travelTo(pkt('2026-11-13 08:00'));
    expect(app(SendSessionReminders::class)->handle())->toBe(0);

    Notification::assertSentToTimes($order->user, SessionReminder::class, 1);
    Notification::assertSentTo($order->user, SessionReminder::class, fn (SessionReminder $n): bool => $n->bookings->count() === 2);
    Notification::assertNotSentTo($cancelled->user, SessionReminder::class);
    expect(NotificationLog::query()->where('type', 'session_reminder')->count())->toBe(1);
});

it('puts riding rules, the arrival time when set, the pin and the tickets in the reminder', function (): void {
    $order = paidOrderAt('2026-11-14 08:00');
    $booking = $order->bookings()->firstOrFail();
    $reminder = fn (): string => (string) (new SessionReminder($booking->slot, $order->bookings()->get()))->toMail($order->user)->render();

    expect($reminder())->toContain(SECRET_PIN)
        ->toContain($booking->ticket_code)
        ->toContain('Before you come')
        ->not->toContain('Please arrive by');

    app(UpdateSettings::class)->handle([SettingKey::ArrivalMinutes->value => 20]);

    expect($reminder())->toContain('Please arrive by')->toContain('7:40 am');
});

it('cancels a slot: credits back even after the cutoff, paid orders flagged, holds released, one email each', function (): void {
    Notification::fake();
    $this->travelTo(pkt('2026-11-13 20:00'));
    $slot = Slot::factory()->at(pkt('2026-11-14 08:00'))->create(['capacity' => 6]); // inside the 48 h cutoff

    // A family with a pack: two riders on credits.
    $family = User::factory()->create();
    $pack = CreditPack::factory()->create(['user_id' => $family->id, 'order_id' => Order::factory()->for($family)->status(OrderStatus::Paid), 'credits_total' => 8, 'credits_used' => 2, 'expires_at' => now()->addWeeks(4)]);
    foreach (Rider::factory()->count(2)->for($family)->create() as $rider) {
        Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'credit_pack_id' => $pack->id]);
    }

    // A paid single booking.
    $payer = User::factory()->create();
    $paidOrder = app(CreateOrder::class)->handle($payer, Product::factory()->create(), $slot->id, [Rider::factory()->for($payer)->create()->id]);
    app(FulfilOrder::class)->handle($paidOrder);

    // An unpaid order holding a seat.
    $holder = User::factory()->create();
    $held = app(CreateOrder::class)->handle($holder, Product::factory()->create(), $slot->id, [Rider::factory()->for($holder)->create()->id]);

    app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Cancelled, User::factory()->admin()->create(), 'Heavy rain');

    expect(Booking::query()->where('slot_id', $slot->id)->where('status', BookingStatus::Confirmed)->count())->toBe(0)
        ->and($pack->refresh()->credits_used)->toBe(0)
        ->and(CreditLedgerEntry::query()->where('credit_pack_id', $pack->id)->where('reason', CreditReason::Returned)->count())->toBe(2)
        ->and($paidOrder->refresh()->meta['needs_review'] ?? null)->toContain('Refund or move')
        ->and($held->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(BookingHold::query()->where('slot_id', $slot->id)->exists())->toBeFalse();

    Notification::assertSentToTimes($family, SlotCancelledNotice::class, 1);
    Notification::assertSentTo($family, SlotCancelledNotice::class, fn (SlotCancelledNotice $n): bool => $n->creditsReturned === 2 && ! $n->paidBookings);
    Notification::assertSentTo($payer, SlotCancelledNotice::class, fn (SlotCancelledNotice $n): bool => $n->paidBookings && $n->creditsReturned === 0);
    Notification::assertSentTo($holder, SlotCancelledNotice::class);

    $html = (string) (new SlotCancelledNotice($slot->refresh(), Booking::query()->where('user_id', $family->id)->get(), 2, false))->toMail($family)->render();
    expect($html)->toContain('Heavy rain')->toContain('credits have')->toContain('use=credits');
});

it('warns once about credits expiring within 7 days', function (): void {
    Notification::fake();
    $soon = CreditPack::factory()->create(['credits_total' => 8, 'credits_used' => 3, 'expires_at' => now()->addDays(6)]);
    $later = CreditPack::factory()->create(['credits_total' => 8, 'credits_used' => 3, 'expires_at' => now()->addDays(9)]);
    $usedUp = CreditPack::factory()->create(['credits_total' => 8, 'credits_used' => 8, 'expires_at' => now()->addDays(3)]);

    expect(app(SendCreditExpiryWarnings::class)->handle())->toBe(1)
        ->and(app(SendCreditExpiryWarnings::class)->handle())->toBe(0);

    Notification::assertSentTo($soon->user, CreditsExpiringSoon::class);
    Notification::assertNotSentTo($later->user, CreditsExpiringSoon::class);
    Notification::assertNotSentTo($usedUp->user, CreditsExpiringSoon::class);

    expect((string) (new CreditsExpiringSoon($soon))->toMail($soon->user)->render())->toContain('5 credits');
});

it('thanks riders who attended, and asks for a review only when the link is set', function (): void {
    Notification::fake();
    $this->travelTo(pkt('2026-11-10 09:00'));
    $attended = paidOrderAt('2026-11-14 08:00');
    $noShow = paidOrderAt('2026-11-14 15:00');

    $this->travelTo(pkt('2026-11-14 18:00'));
    $attended->bookings()->update(['status' => BookingStatus::Attended]);
    $noShow->bookings()->update(['status' => BookingStatus::NoShow]);

    $this->travelTo(pkt('2026-11-14 17:30')); // 15:00 slot ended 30 min ago
    app(SendThankYous::class)->handle();
    Notification::assertSentTo($attended->user, ThankYou::class);
    Notification::assertNotSentTo($noShow->user, ThankYou::class);

    $this->travelTo(pkt('2026-11-14 20:00'));
    expect(app(SendThankYous::class)->handle())->toBe(0);

    $slot = $attended->bookings()->firstOrFail()->slot;
    $render = fn (): string => (string) (new ThankYou($slot, $attended->bookings()->get()))->toMail($attended->user)->render();
    expect($render())->not->toContain('Review us on Google')->toContain('Rate your session');

    app(UpdateSettings::class)->handle([SettingKey::GoogleReviewUrl->value => 'https://g.page/r/ridepakistan/review']);
    expect($render())->toContain('Review us on Google')->toContain('https://g.page/r/ridepakistan/review');
});

it('skips anonymised and missing addresses', function (): void {
    Notification::fake();
    $order = paidOrderAt('2026-11-20 08:00');
    $order->user->forceFill(['email' => 'deleted-'.$order->user_id.'@anonymised.invalid'])->save();

    expect(app(Notifier::class)->send($order->user, new OrderConfirmed($order)))->toBeFalse();
    Notification::assertNothingSent();
});

it('keeps WhatsApp off by default, and the channel sends nothing when switched on', function (): void {
    $order = paidOrderAt('2026-11-20 08:00');
    $notification = new OrderConfirmed($order);

    expect($notification->via($order->user))->toBe(['mail']);

    config(['notifications.whatsapp.enabled' => true]);
    expect($notification->via($order->user))->toBe(['mail', WhatsAppChannel::class]);

    $order->user->forceFill(['phone' => '+923001234567'])->save();
    Log::spy();
    (new WhatsAppChannel)->send($order->user, $notification);
    Log::shouldHaveReceived('info')->withArgs(fn (string $msg, array $ctx): bool => str_contains($msg, 'not sent') && ! in_array('+923001234567', $ctx, true))->once();
});

it('lets admins resend emails, logs who did it, and refuses staff', function (): void {
    Notification::fake();
    $order = paidOrderAt('2026-11-20 08:00');
    $admin = User::factory()->admin()->withMfa()->create();

    expect(ResendNotification::optionsForOrder($order))->toHaveKey('order_confirmed');

    app(ResendNotification::class)->forOrder($order, 'order_confirmed', $admin);
    app(ResendNotification::class)->forOrder($order, 'order_confirmed', $admin); // resends are never deduplicated
    app(ResendNotification::class)->forBooking($order->bookings()->firstOrFail(), 'session_reminder', $admin);

    Notification::assertSentToTimes($order->user, OrderConfirmed::class, 2);
    Notification::assertSentTo($order->user, SessionReminder::class);
    expect(NotificationLog::query()->where('resent_by', $admin->id)->count())->toBe(3);

    expect(fn () => app(ResendNotification::class)->forOrder($order, 'transfer_rejected', $admin))->toThrow(BookingRejected::class)
        ->and(fn () => app(ResendNotification::class)->forOrder($order, 'order_confirmed', User::factory()->staff()->create()))->toThrow(BookingRejected::class, 'Only admins');
});

it('resends from the admin order page', function (): void {
    Notification::fake();
    $order = paidOrderAt('2026-11-20 08:00');
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('resend_email', data: ['type' => 'order_confirmed'])
        ->assertNotified('Email queued for '.$order->user->email);

    Notification::assertSentTo($order->user, OrderConfirmed::class);
});

it('schedules reminders, thank-yous and credit warnings', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($e): string => (string) $e->command)->implode("\n");

    expect($commands)->toContain('notifications:send reminders')
        ->toContain('notifications:send thanks')
        ->toContain('notifications:send credits');
});
