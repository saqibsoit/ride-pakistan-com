<?php

declare(strict_types=1);

use App\Actions\Weather\SendWeatherNotice;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\SlotStatus;
use App\Enums\WeatherNoticeTemplate;
use App\Exceptions\WeatherNoticeRejected;
use App\Filament\Resources\Slots\Pages\ViewSlot;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\SlotCancelledNotice;
use App\Notifications\WeatherNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** A slot on Sat 14 Nov 08:00 with a family (two riders) and one other customer booked, plus a cancelled booking. */
function weatherNoticeSlot(): array
{
    $slot = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create(['capacity' => 6]);

    $family = User::factory()->create();
    $pack = CreditPack::factory()->create(['user_id' => $family->id, 'order_id' => Order::factory()->for($family)->status(OrderStatus::Paid), 'credits_total' => 8, 'credits_used' => 2, 'expires_at' => now()->addWeeks(4)]);
    foreach (Rider::factory()->count(2)->for($family)->create() as $rider) {
        Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => $rider->id, 'credit_pack_id' => $pack->id, 'status' => BookingStatus::Confirmed]);
    }

    $other = User::factory()->create();
    Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => Rider::factory()->for($other), 'status' => BookingStatus::Confirmed]);

    $gone = User::factory()->create();
    Booking::factory()->create(['slot_id' => $slot->id, 'rider_id' => Rider::factory()->for($gone), 'status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

    return [$slot, $family, $other, $gone, $pack];
}

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 18:00', 'Asia/Karachi'));
    $this->admin = User::factory()->admin()->withMfa()->create();
});

it('emails each booked customer once, listing their riders, and logs it without the text', function (): void {
    [$slot, $family, $other, $gone] = weatherNoticeSlot();

    $count = app(SendWeatherNotice::class)->handle($slot, WeatherNoticeTemplate::Update, "Strong wind is forecast.\n\nWe'll decide by 06:00.", $this->admin);

    expect($count)->toBe(2);
    Notification::assertSentToTimes($family, WeatherNotice::class, 1);
    Notification::assertSentTo($family, WeatherNotice::class, fn (WeatherNotice $n): bool => $n->bookings->count() === 2);
    Notification::assertSentToTimes($other, WeatherNotice::class, 1);
    Notification::assertNotSentTo($gone, WeatherNotice::class);

    $log = Activity::query()->where('log_name', 'weather')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties->get('template'))->toBe('update')
        ->and($log->properties->get('customers'))->toBe(2)
        ->and($log->properties->toJson())->not->toContain('Strong wind');
});

it('writes the notice email with the message, the slot and the customer’s tickets', function (): void {
    [$slot, $family] = weatherNoticeSlot();
    $bookings = Booking::query()->where('user_id', $family->id)->get();

    $mail = (new WeatherNotice($slot, WeatherNoticeTemplate::Moved, "We're moving you to Sunday.\n\nSee you <then>.", $bookings))->toMail($family);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Your session is moving: Sat 14 Nov 2026, 08:00–10:00')
        ->and($html)->toContain('moving you to Sunday')
        ->toContain('See you &lt;then&gt;.')
        ->toContain($bookings[0]->ticket_code)
        ->toContain($bookings[1]->ticket_code)
        ->toContain($bookings[0]->rider->name);
});

it('cancels the slot through the usual flow with the message as the reason, sending only the cancellation email', function (): void {
    [$slot, $family, $other, , $pack] = weatherNoticeSlot();

    $count = app(SendWeatherNotice::class)->handle($slot, WeatherNoticeTemplate::Cancelled, 'Thunderstorms forecast all morning.', $this->admin);

    expect($count)->toBe(2)
        ->and($slot->refresh()->status)->toBe(SlotStatus::Cancelled)
        ->and($slot->cancel_reason)->toBe('Thunderstorms forecast all morning.')
        ->and($slot->cancelled_by)->toBe($this->admin->id)
        ->and($pack->refresh()->credits_used)->toBe(0)
        ->and(Booking::query()->where('slot_id', $slot->id)->where('status', BookingStatus::Confirmed)->count())->toBe(0);

    Notification::assertSentToTimes($family, SlotCancelledNotice::class, 1);
    Notification::assertSentToTimes($other, SlotCancelledNotice::class, 1);
    Notification::assertNotSentTo([$family, $other], WeatherNotice::class);

    $html = (string) (new SlotCancelledNotice($slot, Booking::query()->where('user_id', $family->id)->get(), 2, false))->toMail($family)->render();
    expect($html)->toContain('Thunderstorms forecast all morning.');
});

it('fills the template with the session time', function (): void {
    [$slot] = weatherNoticeSlot();

    expect(app(SendWeatherNotice::class)->defaultMessage($slot, WeatherNoticeTemplate::Update))
        ->toContain('Sat 14 Nov 2026, 08:00–10:00')
        ->not->toContain(':session');
});

it('refuses empty, too long, past, cancelled and empty-slot notices', function (): void {
    [$slot] = weatherNoticeSlot();
    $send = app(SendWeatherNotice::class);

    expect(fn () => $send->handle($slot, WeatherNoticeTemplate::Update, '   ', $this->admin))->toThrow(WeatherNoticeRejected::class, 'Write a message')
        ->and(fn () => $send->handle($slot, WeatherNoticeTemplate::Cancelled, str_repeat('a', 501), $this->admin))->toThrow(WeatherNoticeRejected::class, '500')
        ->and(fn () => $send->handle($slot, WeatherNoticeTemplate::Update, str_repeat('a', 2001), $this->admin))->toThrow(WeatherNoticeRejected::class, '2000');

    $empty = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 15:00', 'Asia/Karachi'))->create();
    expect(fn () => $send->handle($empty, WeatherNoticeTemplate::Update, 'Hi', $this->admin))->toThrow(WeatherNoticeRejected::class, 'Nobody');

    $this->travelTo(CarbonImmutable::parse('2026-11-14 09:00', 'Asia/Karachi'));
    expect(fn () => $send->handle($slot, WeatherNoticeTemplate::Update, 'Hi', $this->admin))->toThrow(WeatherNoticeRejected::class, 'upcoming');

    Notification::assertNothingSent();
});

it('never sends the same message twice to the same customers, and limits notices per slot', function (): void {
    [$slot, $family] = weatherNoticeSlot();
    $send = app(SendWeatherNotice::class);

    $send->handle($slot, WeatherNoticeTemplate::Update, 'Windy.', $this->admin);
    expect(fn () => $send->handle($slot, WeatherNoticeTemplate::Update, 'Windy.', $this->admin))->toThrow(WeatherNoticeRejected::class, 'already sent');
    Notification::assertSentToTimes($family, WeatherNotice::class, 1);

    foreach (['Update 2', 'Update 3', 'Update 4'] as $message) {
        $send->handle($slot, WeatherNoticeTemplate::Update, $message, $this->admin);
    }

    expect(fn () => $send->handle($slot, WeatherNoticeTemplate::Update, 'Update 6', $this->admin))->toThrow(WeatherNoticeRejected::class, 'Too many');
    RateLimiter::clear('weather-notice:'.$slot->id);
});

it('lets an admin send it from the slot page, with the template prefilled', function (): void {
    [$slot, $family] = weatherNoticeSlot();
    $this->actingAs($this->admin);

    Livewire::test(ViewSlot::class, ['record' => $slot->id])
        ->assertActionVisible('weatherNotice')
        ->mountAction('weatherNotice')
        ->assertSchemaStateSet(['template' => 'update'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    Notification::assertSentTo($family, WeatherNotice::class, fn (WeatherNotice $n): bool => str_contains($n->message, 'Sat 14 Nov 2026, 08:00–10:00'));
});

it('requires a message in the admin form', function (): void {
    [$slot] = weatherNoticeSlot();
    $this->actingAs($this->admin);

    Livewire::test(ViewSlot::class, ['record' => $slot->id])
        ->callAction('weatherNotice', data: ['template' => 'update', 'message' => ''])
        ->assertHasActionErrors(['message' => 'required']);

    Notification::assertNothingSent();
});

it('hides the weather notice from staff and trainers, and on cancelled slots', function (): void {
    [$slot] = weatherNoticeSlot();

    $this->actingAs(User::factory()->staff()->withMfa()->create());
    Livewire::test(ViewSlot::class, ['record' => $slot->id])->assertActionHidden('weatherNotice');

    expect(User::factory()->staff()->create()->can('sendWeatherNotice', $slot))->toBeFalse()
        ->and(User::factory()->trainer()->create()->can('sendWeatherNotice', $slot))->toBeFalse()
        ->and(User::factory()->create()->can('sendWeatherNotice', $slot))->toBeFalse()
        ->and($this->admin->can('sendWeatherNotice', $slot))->toBeTrue();

    $cancelled = Slot::factory()->at(CarbonImmutable::parse('2026-11-15 08:00', 'Asia/Karachi'))->create(['status' => SlotStatus::Cancelled]);
    $this->actingAs($this->admin);
    Livewire::test(ViewSlot::class, ['record' => $cancelled->id])->assertActionHidden('weatherNotice');
});
