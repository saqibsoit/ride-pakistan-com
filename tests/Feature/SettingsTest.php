<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

it('has a default for every setting except the undecided ones', function (SettingKey $key): void {
    $undecided = [SettingKey::MinRiderAge, SettingKey::RefundPolicy, SettingKey::MapPinUrl];

    if (in_array($key, $undecided, true)) {
        expect(true)->toBeTrue();

        return;
    }

    expect($key->default())->not->toBeNull()->not->toBe('')->not->toBe([]);
})->with(SettingKey::cases());

it('reads typed defaults (PLAN section 2 open decisions)', function (): void {
    $settings = app(Settings::class);

    expect($settings->int(SettingKey::RidersPerSlot))->toBe(6)
        ->and($settings->int(SettingKey::TransferHoldHours))->toBe(24)
        ->and($settings->int(SettingKey::CancellationCutoffHours))->toBe(48)
        ->and($settings->int(SettingKey::MinRiderAge))->toBeNull()
        ->and($settings->string(SettingKey::Location))->toBe('Islamabad')
        ->and($settings->list(SettingKey::AiCrawlers))->toContain('GPTBot', 'ClaudeBot');
});

it('saves only changed values, normalised to their type, and logs the keys', function (): void {
    $admin = User::factory()->create();

    $changed = app(UpdateSettings::class)->handle([
        'booking.riders_per_slot' => '8',
        'booking.transfer_hold_hours' => 24,
        'policies.riding_rules' => ['  Helmets on  ', '', 'Closed shoes'],
        'not.a.setting' => 'ignored',
    ], $admin);

    expect($changed)->toBe(['booking.riders_per_slot', 'policies.riding_rules'])
        ->and(Setting::query()->count())->toBe(2)
        ->and(app(Settings::class)->int(SettingKey::RidersPerSlot))->toBe(8)
        ->and(app(Settings::class)->list(SettingKey::RidingRules))->toBe(['Helmets on', 'Closed shoes']);

    $log = Activity::query()->where('log_name', 'settings')->sole();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->properties->get('keys'))->toBe($changed);
});

it('does not log setting values, which may be sensitive', function (): void {
    app(UpdateSettings::class)->handle(['bank.iban' => 'PK00TEST0000000000000000']);

    expect(Activity::query()->where('log_name', 'settings')->sole()->properties->toJson())
        ->not->toContain('PK00TEST');
});

it('caches stored values and refreshes them on save', function (): void {
    $settings = app(Settings::class);
    $settings->string(SettingKey::Email);

    // A direct DB write is not seen while the cache holds...
    DB::table('settings')->insert(['key' => 'contact.email', 'value' => json_encode('direct@example.com')]);
    expect($settings->string(SettingKey::Email))->not->toBe('direct@example.com');

    // ...and saving through the action refreshes it.
    app(UpdateSettings::class)->handle(['contact.email' => 'saved@example.com']);
    expect($settings->string(SettingKey::Email))->toBe('saved@example.com');
});

it('fills policy tokens and drops items whose value is undecided', function (): void {
    app(UpdateSettings::class)->handle([
        'policies.booking' => ['Cancel up to :cutoff hours before.', 'Riders must be :min_age or older.', 'No transport.'],
        'booking.cancellation_cutoff_hours' => 24,
    ]);

    expect(app(Settings::class)->policies(SettingKey::BookingPolicies))
        ->toBe(['Cancel up to 24 hours before.', 'No transport.']);

    app(UpdateSettings::class)->handle(['booking.min_rider_age' => 12]);

    expect(app(Settings::class)->policies(SettingKey::BookingPolicies))
        ->toContain('Riders must be 12 or older.');
});

it('declares a type and label for every key', function (SettingKey $key): void {
    expect($key->type())->toBeInstanceOf(SettingType::class)
        ->and($key->label())->not->toBeEmpty();
})->with(SettingKey::cases());

it('keeps bank details out of the code (they come from .env)', function (): void {
    $source = (string) file_get_contents(base_path('config/settings.php'));

    expect($source)->not->toContain('PK91SCBL');
});
