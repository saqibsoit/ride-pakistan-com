<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Actions\Weather\AlertForecastFailure;
use App\Actions\Weather\FetchForecast;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\WeatherFetch;
use App\Models\WeatherForecastHour;
use App\Notifications\WeatherForecastsFailing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\Support\OpenMeteoFixture;

function weatherFetchAt(string $pakistanTime): void
{
    test()->travelTo(CarbonImmutable::parse($pakistanTime, 'Asia/Karachi'));
}

beforeEach(function (): void {
    Sleep::fake();
    Notification::fake();
    weatherFetchAt('2026-11-13 18:00');
});

it('fetches the hourly forecast for the venue in UTC and caches it', function (): void {
    OpenMeteoFixture::enable();
    Http::fake(['api.open-meteo.com/*' => Http::response(OpenMeteoFixture::response('2026-11-13', [
        '2026-11-14 03:00' => ['wind_gusts_10m' => 55.4, 'precipitation_probability' => 80],
    ]))]);

    expect(app(FetchForecast::class)->handle())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')
        && $request['latitude'] === 33.6844
        && $request['longitude'] === 73.0479
        && $request['timezone'] === 'UTC'
        && $request['wind_speed_unit'] === 'kmh'
        && str_contains((string) $request['hourly'], 'wind_gusts_10m')
        && str_contains((string) $request['hourly'], 'weather_code'));

    $hour = WeatherForecastHour::query()->where('hour_at', CarbonImmutable::parse('2026-11-14 03:00', 'UTC'))->sole();

    expect(WeatherForecastHour::query()->count())->toBe(72)
        ->and($hour->wind_gusts_kmh)->toBe(55.4)
        ->and($hour->precipitation_probability)->toBe(80)
        ->and(WeatherFetch::query()->sole()->succeeded)->toBeTrue();
});

it('replaces cached hours with the newer forecast and prunes old ones', function (): void {
    OpenMeteoFixture::enable();
    OpenMeteoFixture::cache('2026-11-10 00:00', 24);
    Http::fake(['api.open-meteo.com/*' => Http::sequence()
        ->push(OpenMeteoFixture::response('2026-11-13', ['2026-11-14 03:00' => ['temperature_2m' => 20.0]]))
        ->push(OpenMeteoFixture::response('2026-11-13', ['2026-11-14 03:00' => ['temperature_2m' => 25.0]]))]);

    app(FetchForecast::class)->handle();
    app(FetchForecast::class)->handle();

    expect(WeatherForecastHour::query()->where('hour_at', CarbonImmutable::parse('2026-11-14 03:00', 'UTC'))->sole()->temperature_c)->toBe(25.0)
        ->and(WeatherForecastHour::query()->where('hour_at', '<', CarbonImmutable::parse('2026-11-11 13:00', 'UTC'))->exists())->toBeFalse();
});

it('sends nothing while switched off or without coordinates', function (array $settings): void {
    app(UpdateSettings::class)->handle($settings);
    Http::fake();

    expect(app(FetchForecast::class)->handle())->toBeNull();
    $this->artisan('weather:check')->assertSuccessful();

    Http::assertNothingSent();
    expect(WeatherFetch::query()->count())->toBe(0);
})->with([
    'off' => [['weather.enabled' => false, 'weather.latitude' => 33.7, 'weather.longitude' => 73.0]],
    'no coordinates' => [['weather.enabled' => true]],
]);

it('fetches twice a day: when 06:00 or 18:00 Pakistan time has passed since the last success', function (): void {
    OpenMeteoFixture::enable();
    Http::fake(['api.open-meteo.com/*' => Http::response(OpenMeteoFixture::response('2026-11-13'))]);

    $this->artisan('weather:check')->assertSuccessful(); // 18:00: due (nothing cached)
    weatherFetchAt('2026-11-13 19:00');
    $this->artisan('weather:check')->assertSuccessful(); // cached: not due
    weatherFetchAt('2026-11-14 05:59');
    $this->artisan('weather:check')->assertSuccessful(); // still the 18:00 forecast
    weatherFetchAt('2026-11-14 06:00');
    $this->artisan('weather:check')->assertSuccessful(); // due again

    Http::assertSentCount(2);

    $this->artisan('weather:check --force')->assertSuccessful();
    Http::assertSentCount(3);
});

it('runs the check hourly from the scheduler', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'weather:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('logs a failed fetch without throwing, for errors, bad data and timeouts', function (mixed $response): void {
    OpenMeteoFixture::enable();
    OpenMeteoFixture::cache('2026-11-13 12:00', 48);
    Log::spy();
    Http::fake(['api.open-meteo.com/*' => $response]);

    expect(app(FetchForecast::class)->handle())->toBeFalse();

    $fetch = WeatherFetch::query()->sole();
    expect($fetch->succeeded)->toBeFalse()
        ->and($fetch->error)->not->toBeEmpty()
        // The last good forecast is kept for the badges.
        ->and(WeatherForecastHour::query()->count())->toBe(48);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'Weather forecast fetch failed.')->once();
})->with([
    'server error' => fn () => Http::response('oops', 500),
    'rate limited' => fn () => Http::response(['error' => true, 'reason' => 'limit'], 429),
    'not json' => fn () => Http::response('<html>', 200),
    'missing variable' => fn () => Http::response(['hourly' => ['time' => ['2026-11-14T03:00'], 'temperature_2m' => [10]]], 200),
    'mismatched lengths' => fn () => Http::response(['hourly' => [
        'time' => ['2026-11-14T03:00', '2026-11-14T04:00'],
        'precipitation_probability' => [1], 'precipitation' => [0], 'wind_speed_10m' => [1],
        'wind_gusts_10m' => [1], 'temperature_2m' => [1], 'weather_code' => [1],
    ]], 200),
    'connection' => fn () => Http::failedConnection(),
]);

it('emails every admin once when forecasts have failed for 12 hours', function (): void {
    OpenMeteoFixture::enable();
    $admins = User::factory()->admin()->count(2)->create();
    $staff = User::factory()->staff()->create();
    Http::fake(['api.open-meteo.com/*' => Http::response('down', 503)]);

    weatherFetchAt('2026-11-13 06:00');
    WeatherFetch::query()->create(['attempted_at' => now(), 'succeeded' => true]);

    // 18:00: first failure, 12 hours after the last success but only 0 hours of failing.
    weatherFetchAt('2026-11-13 18:00');
    $this->artisan('weather:check')->assertSuccessful();

    foreach (['2026-11-13 23:00', '2026-11-14 05:00'] as $time) {
        weatherFetchAt($time);
        $this->artisan('weather:check')->assertSuccessful();
    }

    Notification::assertNothingSent();

    // 06:00: failing for 12 hours.
    weatherFetchAt('2026-11-14 06:00');
    $this->artisan('weather:check')->assertSuccessful();

    Notification::assertSentTo($admins, WeatherForecastsFailing::class);
    Notification::assertNotSentTo($staff, WeatherForecastsFailing::class);
    Notification::assertCount(2);

    // Still failing an hour later: no repeat.
    weatherFetchAt('2026-11-14 07:00');
    $this->artisan('weather:check')->assertSuccessful();
    Notification::assertCount(2);
});

it('alerts again only after a success ends the outage and a new one lasts 12 hours', function (): void {
    OpenMeteoFixture::enable();
    User::factory()->admin()->create();

    $fail = fn (string $at) => WeatherFetch::query()->create(['attempted_at' => CarbonImmutable::parse($at, 'Asia/Karachi')->utc(), 'succeeded' => false, 'error' => 'HTTP 503']);

    $fail('2026-11-12 06:00');
    weatherFetchAt('2026-11-12 18:00');
    expect(app(AlertForecastFailure::class)->handle())->toBe(1)
        ->and(app(AlertForecastFailure::class)->handle())->toBe(0);

    WeatherFetch::query()->create(['attempted_at' => CarbonImmutable::parse('2026-11-12 19:00', 'Asia/Karachi')->utc(), 'succeeded' => true]);
    $fail('2026-11-13 06:00');

    weatherFetchAt('2026-11-13 17:59');
    expect(app(AlertForecastFailure::class)->handle())->toBe(0);

    weatherFetchAt('2026-11-13 18:00');
    expect(app(AlertForecastFailure::class)->handle())->toBe(1)
        ->and(NotificationLog::query()->where('type', WeatherForecastsFailing::type())->count())->toBe(2);
});

it('writes a failure email with the times in Pakistan time and no coordinates', function (): void {
    OpenMeteoFixture::enable();
    $admin = User::factory()->admin()->create();
    $first = WeatherFetch::query()->create(['attempted_at' => CarbonImmutable::parse('2026-11-13 06:00', 'Asia/Karachi')->utc(), 'succeeded' => false, 'error' => 'Open-Meteo answered HTTP 503.']);

    $html = (string) (new WeatherForecastsFailing($first, CarbonImmutable::parse('2026-11-12 18:00', 'Asia/Karachi'), 'Open-Meteo answered HTTP 503.'))
        ->toMail($admin)->render();

    expect($html)->toContain('Fri 13 Nov 2026, 06:00')
        ->toContain('Thu 12 Nov 2026, 18:00')
        ->toContain('HTTP 503')
        ->not->toContain('33.68')
        ->not->toContain('73.04');
});
