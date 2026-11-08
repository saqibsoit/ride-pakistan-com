<?php

declare(strict_types=1);

use App\Actions\Slots\CreateManualSlot;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Exceptions\SlotNotCreated;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function karachi(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'Asia/Karachi');
}

function createManual(string $start, string $end, int $capacity = 4, ?User $actor = null): Slot
{
    return app(CreateManualSlot::class)->handle(karachi($start), karachi($end), $capacity, $actor);
}

beforeEach(function (): void {
    // Monday 2 November 2026, 09:00 Pakistan time.
    $this->travelTo(karachi('2026-11-02 09:00'));
});

it('creates an open manual slot on any day, stored in UTC, and logs it', function (): void {
    $admin = User::factory()->admin()->create();

    // A Wednesday: no rule runs on weekdays.
    $slot = createManual('2026-11-04 17:30', '2026-11-04 19:00', 3, $admin);

    expect($slot->source)->toBe(SlotSource::Manual)
        ->and($slot->isManual())->toBeTrue()
        ->and($slot->schedule_rule_id)->toBeNull()
        ->and($slot->status)->toBe(SlotStatus::Open)
        ->and($slot->capacity)->toBe(3)
        ->and(DB::table('slots')->value('starts_at'))->toBe('2026-11-04 12:30:00')
        ->and(DB::table('slots')->value('ends_at'))->toBe('2026-11-04 14:00:00')
        ->and($slot->refresh()->label())->toBe('Wed 4 Nov 2026, 17:30–19:00');

    $log = Activity::query()->where('log_name', 'slots')->where('event', 'created_manual')->sole();

    expect($log->subject_id)->toBe($slot->id)
        ->and($log->causer_id)->toBe($admin->id)
        ->and($log->properties['capacity'])->toBe(3);
});

it('drops seconds from the times given', function (): void {
    $slot = createManual('2026-11-04 17:30:45', '2026-11-04 19:00:10');

    expect(DB::table('slots')->value('starts_at'))->toBe('2026-11-04 12:30:00')
        ->and($slot->ends_at->second)->toBe(0);
});

it('refuses a slot that does not start in the future', function (string $start): void {
    createManual($start, '2026-11-02 23:00');
})->with([
    'in the past' => '2026-11-01 08:00',
    'right now' => '2026-11-02 09:00',
])->throws(SlotNotCreated::class, 'must start in the future');

it('refuses a slot that does not end after it starts', function (string $end): void {
    createManual('2026-11-04 17:00', $end);
})->with([
    'same time' => '2026-11-04 17:00',
    'earlier' => '2026-11-04 16:00',
])->throws(SlotNotCreated::class, 'must end after it starts');

it('refuses a capacity outside the allowed range', function (int $capacity): void {
    createManual('2026-11-04 17:00', '2026-11-04 18:00', $capacity);
})->with([0, -1, 51])->throws(SlotNotCreated::class, 'between 1 and 50');

it('accepts the capacity limits', function (int $capacity): void {
    expect(createManual('2026-11-04 17:00', '2026-11-04 18:00', $capacity)->capacity)->toBe($capacity);
})->with([1, 50]);

it('refuses a start time another slot already has, rule or manual', function (string $source): void {
    $factory = Slot::factory()->at(karachi('2026-11-07 08:00'));
    $existing = ($source === 'manual' ? $factory->manual() : $factory)->create();

    expect(fn () => createManual('2026-11-07 08:00', '2026-11-07 09:00'))
        ->toThrow(SlotNotCreated::class, 'A slot already starts at Sat 7 Nov 2026, 08:00.');

    expect(Slot::query()->count())->toBe(1)
        ->and($existing->refresh()->ends_at->equalTo(karachi('2026-11-07 10:00')))->toBeTrue()
        ->and(Activity::query()->where('event', 'created_manual')->exists())->toBeFalse();
})->with(['rule', 'manual']);

it('allows a manual slot that overlaps another slot without sharing its start', function (): void {
    Slot::factory()->at(karachi('2026-11-07 08:00'))->create();

    $slot = createManual('2026-11-07 09:00', '2026-11-07 11:00');

    expect($slot->exists)->toBeTrue()
        ->and(Slot::query()->count())->toBe(2);
});
