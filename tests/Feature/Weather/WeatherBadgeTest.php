<?php

declare(strict_types=1);

use App\Actions\Weather\CheckSlotWeather;
use App\Enums\SettingKey;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Slots\Pages\ListSlots;
use App\Filament\Resources\Slots\Pages\ViewSlot;
use App\Models\Slot;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\OpenMeteoFixture;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-13 18:00', 'Asia/Karachi'));
    OpenMeteoFixture::enable();
    OpenMeteoFixture::cache('2026-11-13 00:00', 120, [
        '2026-11-14 03:00' => ['wind_gusts_kmh' => 58.0, 'weather_code' => 95],
        '2026-11-16 03:00' => ['weather_code' => 95],
    ]);

    // Sat 08:00 (warning), Sat 15:00 (clear), Mon 08:00 (outside 48 h).
    $this->stormy = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create();
    $this->calm = Slot::factory()->at(CarbonImmutable::parse('2026-11-14 15:00', 'Asia/Karachi'))->create();
    $this->later = Slot::factory()->at(CarbonImmutable::parse('2026-11-16 08:00', 'Asia/Karachi'))->create();

    app(CheckSlotWeather::class)->handle();
});

it('shows the weather level on the run sheet list and the run sheet', function (): void {
    $staff = User::factory()->staff()->withMfa()->create();

    $this->actingAs($staff)->get(route('team.sessions.index'))
        ->assertOk()
        ->assertSee('Weather: Warning')
        ->assertSee('Weather: Clear')
        ->assertSee('gusts 58 km/h, thunderstorm');

    $this->actingAs($staff)->get(route('team.sessions.show', $this->stormy))
        ->assertOk()
        ->assertSee('Weather: Warning')
        ->assertSee('gusts 58 km/h, thunderstorm');

    $this->actingAs($staff)->get(route('team.sessions.show', $this->later))
        ->assertOk()
        ->assertDontSee('Weather:');
});

it('drops the badge once the session is no longer in the next 48 hours', function (): void {
    expect($this->stormy->refresh()->currentWeather())->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:30', 'Asia/Karachi'));

    expect($this->stormy->refresh()->currentWeather())->toBeNull();
});

it('shows the weather column on the admin slot list and the slot page', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    Livewire::test(ListSlots::class)
        ->assertTableColumnStateSet('weather_badge', 'Weather: Warning', $this->stormy)
        ->assertTableColumnStateSet('weather_badge', 'Weather: Clear', $this->calm)
        ->assertTableColumnStateSet('weather_badge', null, $this->later);

    Livewire::test(ViewSlot::class, ['record' => $this->stormy->id])
        ->assertSee('Crossed thresholds')
        ->assertSee('gusts 58 km/h, thunderstorm');
});

it('keeps the venue coordinates private: admin settings only, never on public pages', function (): void {
    expect(SettingKey::WeatherLatitude->isPrivate())->toBeTrue()
        ->and(SettingKey::WeatherLongitude->isPrivate())->toBeTrue();

    $this->get('/')->assertOk()->assertDontSee('33.6844')->assertDontSee('73.0479');
    $this->get('/llms.txt')->assertOk()->assertDontSee('33.6844')->assertDontSee('73.0479');
    $this->get('/schedule')->assertOk()->assertDontSee('33.6844');

    $this->actingAs(User::factory()->staff()->withMfa()->create());
    expect(ManageSettings::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->admin()->withMfa()->create());
    expect(ManageSettings::canAccess())->toBeTrue();
});

it('saves the weather settings from the settings page', function (): void {
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    Livewire::test(ManageSettings::class)
        ->fillForm([
            'weather__latitude' => '33.7',
            'weather__longitude' => '73.1',
            'weather__cold_c' => '-2',
            'weather__precipitation_mm' => '0.5',
            'weather__recipients' => ['admin', 'trainer'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(Settings::class);
    expect($settings->float(SettingKey::WeatherLatitude))->toBe(33.7)
        ->and($settings->int(SettingKey::WeatherColdC))->toBe(-2)
        ->and($settings->float(SettingKey::WeatherPrecipitationMm))->toBe(0.5)
        ->and($settings->list(SettingKey::WeatherRecipients))->toBe(['admin', 'trainer']);

    Livewire::test(ManageSettings::class)
        ->fillForm(['weather__latitude' => '120'])
        ->call('save')
        ->assertHasFormErrors(['weather__latitude']);
});
