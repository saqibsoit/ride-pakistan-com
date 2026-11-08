<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Actions\Weather\CheckSlotWeather;
use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Enums\WeatherSeverity;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Rider;
use App\Models\Slot;
use App\Models\SlotWeather;
use App\Models\User;
use App\Notifications\WeatherAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\Support\OpenMeteoFixture;

/** Sat 14 Nov 2026 08:00–10:00 Pakistan time (03:00–05:00 UTC). */
function weatherAlertSlot(array $attributes = []): Slot
{
    return Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create(['capacity' => 6, ...$attributes]);
}

/** Cache a forecast with $values in the slot's first hour (03:00 UTC). */
function weatherAlertForecast(array $values = []): void
{
    OpenMeteoFixture::cache('2026-11-13 00:00', 72, $values === [] ? [] : ['2026-11-14 03:00' => $values]);
}

beforeEach(function (): void {
    Sleep::fake();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 18:00', 'Asia/Karachi'));
    OpenMeteoFixture::enable(['weather.recipients' => ['admin', 'staff', 'trainer']]);
    $this->admin = User::factory()->admin()->create();
});

it('emails the team once when a session crosses a threshold, and again only when the level changes', function (): void {
    $slot = weatherAlertSlot();
    $check = app(CheckSlotWeather::class);

    weatherAlertForecast(['wind_gusts_kmh' => 50.0]);
    expect($check->handle())->toBe(1);
    expect($check->handle())->toBe(0); // same level: nothing new

    $weather = SlotWeather::query()->where('slot_id', $slot->id)->sole();
    expect($weather->severity)->toBe(WeatherSeverity::Watch)
        ->and($weather->alerted_severity)->toBe(WeatherSeverity::Watch)
        ->and($weather->summary())->toBe('gusts 50 km/h');

    // Worse: a second email.
    weatherAlertForecast(['wind_gusts_kmh' => 50.0, 'weather_code' => 95]);
    expect($check->handle())->toBe(1);

    // Back down to a watch: another change.
    weatherAlertForecast(['wind_gusts_kmh' => 60.0]);
    expect($check->handle())->toBe(1);

    // Clear after an alert: all clear, then quiet.
    weatherAlertForecast(['wind_gusts_kmh' => 10.0]);
    expect($check->handle())->toBe(1)
        ->and($check->handle())->toBe(0);

    $sent = [];
    Notification::assertSentTo($this->admin, WeatherAlert::class, function (WeatherAlert $n) use (&$sent): bool {
        $sent[] = [$n->severity, $n->previous];

        return true;
    });

    expect($sent)->toBe([
        [WeatherSeverity::Watch, null],
        [WeatherSeverity::Warning, WeatherSeverity::Watch],
        [WeatherSeverity::Watch, WeatherSeverity::Warning],
        [WeatherSeverity::Clear, WeatherSeverity::Watch],
    ]);
});

it('sends nothing for a clear session that never had an alert, but shows it as clear', function (): void {
    $slot = weatherAlertSlot();
    weatherAlertForecast();

    expect(app(CheckSlotWeather::class)->handle())->toBe(0);
    Notification::assertNothingSent();

    expect($slot->weather()->sole()->severity)->toBe(WeatherSeverity::Clear)
        ->and($slot->weather()->sole()->alerted_severity)->toBeNull();
});

it('only looks at open and closed sessions starting in the next 48 hours', function (): void {
    weatherAlertForecast(['weather_code' => 95]);
    OpenMeteoFixture::cache('2026-11-16 00:00', 24, ['2026-11-16 03:00' => ['weather_code' => 95]]);

    $cancelled = weatherAlertSlot(['status' => SlotStatus::Cancelled]);
    $closed = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi')->addMinutes(30))->create(['status' => SlotStatus::Closed]);
    $later = Slot::factory()->at(CarbonImmutable::parse('2026-11-16 08:00', 'Asia/Karachi'))->create();
    $past = Slot::factory()->at(CarbonImmutable::parse('2026-11-13 08:00', 'Asia/Karachi'))->create();

    app(CheckSlotWeather::class)->handle();

    expect(SlotWeather::query()->pluck('slot_id')->all())->toBe([$closed->id]);
    expect([$cancelled->id, $later->id, $past->id])->each->not->toBeIn(SlotWeather::query()->pluck('slot_id')->all());
});

it('does nothing while weather alerts are off', function (): void {
    weatherAlertSlot();
    weatherAlertForecast(['weather_code' => 95]);
    app(UpdateSettings::class)->handle(['weather.enabled' => false]);

    expect(app(CheckSlotWeather::class)->handle())->toBe(0)
        ->and(SlotWeather::query()->count())->toBe(0);
});

it('sends to the chosen roles, and to a trainer only for their own session', function (): void {
    $staff = User::factory()->staff()->create();
    $trainer = User::factory()->trainer()->create();
    $otherTrainer = User::factory()->trainer()->create();
    $customer = User::factory()->create();
    $slot = weatherAlertSlot(['trainer_id' => $trainer->id]);
    Booking::factory()->create(['slot_id' => $slot->id, 'status' => BookingStatus::Confirmed, 'rider_id' => Rider::factory()->for($customer)]);
    weatherAlertForecast(['precipitation_probability' => 90]);

    app(CheckSlotWeather::class)->handle();

    Notification::assertSentTo([$this->admin, $staff, $trainer], WeatherAlert::class);
    Notification::assertNotSentTo([$otherTrainer, $customer], WeatherAlert::class);
});

it('respects a narrower recipient setting', function (): void {
    $staff = User::factory()->staff()->create();
    $trainer = User::factory()->trainer()->create();
    weatherAlertSlot(['trainer_id' => $trainer->id]);
    weatherAlertForecast(['precipitation_probability' => 90]);
    app(UpdateSettings::class)->handle(['weather.recipients' => ['staff']]);

    app(CheckSlotWeather::class)->handle();

    Notification::assertSentTo($staff, WeatherAlert::class);
    Notification::assertNotSentTo([$this->admin, $trainer], WeatherAlert::class);
});

it('never sends the same alert twice, even if the level was recorded but the mail was already logged', function (): void {
    $slot = weatherAlertSlot();
    weatherAlertForecast(['wind_gusts_kmh' => 50.0]);

    app(CheckSlotWeather::class)->handle();

    // A second run with the row reset to "never alerted" (e.g. a crash after
    // logging but before saving) at the same moment still dedupes on the key.
    SlotWeather::query()->where('slot_id', $slot->id)->update(['alerted_severity' => null, 'alerted_at' => null]);
    app(CheckSlotWeather::class)->handle();

    expect(NotificationLog::query()->where('type', WeatherAlert::type())->count())->toBe(1);
});

it('fetches, assesses and alerts from the hourly command with a mocked API', function (): void {
    $slot = weatherAlertSlot();
    Http::fake(['api.open-meteo.com/*' => Http::response(OpenMeteoFixture::response('2026-11-13', [
        '2026-11-14 04:00' => ['precipitation' => 4.2, 'precipitation_probability' => 95],
    ]))]);

    $this->artisan('weather:check')->expectsOutputToContain('Weather alerts sent: 1.')->assertSuccessful();

    expect($slot->weather()->sole()->severity)->toBe(WeatherSeverity::Warning)
        ->and($slot->weather()->sole()->summary())->toBe('rain chance 95%, rain 4.2 mm');
});

it('writes the alert email with the session, conditions in Pakistan time, riders and links', function (): void {
    $staff = User::factory()->staff()->create();
    $slot = weatherAlertSlot();
    Booking::factory()->count(2)->create(['slot_id' => $slot->id, 'status' => BookingStatus::Confirmed]);
    Booking::factory()->create(['slot_id' => $slot->id, 'status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);
    weatherAlertForecast(['wind_gusts_kmh' => 62.0, 'weather_code' => 95]);

    app(CheckSlotWeather::class)->handle();

    /** @var WeatherAlert $alert */
    $alert = Notification::sent($this->admin, WeatherAlert::class)->first();
    $mail = $alert->toMail($this->admin);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Weather warning: Sat 14 Nov, 08:00–10:00')
        ->and($html)->toContain('Sat 14 Nov 2026, 08:00–10:00')
        ->toContain('Gusts 62 km/h at 08:00')
        ->toContain('Thunderstorm (code 95) at 08:00')
        ->toContain('2 of 6')
        ->toContain(route('team.sessions.show', $slot))
        ->toContain('Cancel, move or notify riders in admin')
        ->not->toContain('33.68')
        ->not->toContain('73.04');

    // Staff get the run sheet, not the admin cancel link.
    $staffHtml = (string) $alert->toMail($staff)->render();
    expect($staffHtml)->toContain(route('team.sessions.show', $slot))
        ->not->toContain('Cancel, move or notify riders in admin');
});

it('writes an all-clear email', function (): void {
    $slot = weatherAlertSlot();
    $html = (string) (new WeatherAlert($slot, WeatherSeverity::Clear, WeatherSeverity::Warning, ['crossed' => [], 'hours' => []]))
        ->toMail($this->admin)->render();

    expect($html)->toContain('Weather all clear')
        ->toContain('no longer crosses any alert threshold (it was warning)');
});
