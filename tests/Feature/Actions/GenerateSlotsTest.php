<?php

declare(strict_types=1);

use App\Actions\Slots\GenerateSlots;
use App\Actions\Slots\SlotGenerationResult;
use App\Enums\SettingKey;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Jobs\RegenerateSlots;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\OrderItem;
use App\Models\ScheduleRule;
use App\Models\Setting;
use App\Models\Slot;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** The four PLAN §2 rules: Sat and Sun, 08:00–10:00 and 15:00–17:00. */
function seedPlanRules(int $capacity = 6): void
{
    foreach ([CarbonInterface::SATURDAY, CarbonInterface::SUNDAY] as $day) {
        foreach ([['08:00', '10:00'], ['15:00', '17:00']] as [$start, $end]) {
            ScheduleRule::factory()->create([
                'day_of_week' => $day,
                'start_time' => $start,
                'end_time' => $end,
                'capacity' => $capacity,
            ]);
        }
    }
}

/** Raw UTC start times stored in the slots table. */
function storedStarts(): array
{
    return DB::table('slots')->orderBy('starts_at')->pluck('starts_at')->all();
}

function generate(?int $weeks = null): SlotGenerationResult
{
    return app(GenerateSlots::class)->handle($weeks);
}

beforeEach(function (): void {
    // Rule changes queue a regeneration; these tests call the action directly.
    Queue::fake([RegenerateSlots::class]);

    // Monday 2 November 2026, 09:00 Pakistan time.
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00', 'Asia/Karachi'));
});

it('creates four slots a weekend for the horizon, stored in UTC', function (): void {
    seedPlanRules();

    $result = generate();

    expect($result->created)->toBe(32)
        ->and(Slot::query()->count())->toBe(32)
        ->and(array_slice(storedStarts(), 0, 4))->toBe([
            '2026-11-07 03:00:00',
            '2026-11-07 10:00:00',
            '2026-11-08 03:00:00',
            '2026-11-08 10:00:00',
        ]);

    $first = Slot::query()->orderBy('starts_at')->firstOrFail();

    expect($first->localStartsAt()->format('D H:i'))->toBe('Sat 08:00')
        ->and($first->localEndsAt()->format('H:i'))->toBe('10:00')
        ->and($first->capacity)->toBe(6)
        ->and($first->status)->toBe(SlotStatus::Open);
});

it('is idempotent', function (): void {
    seedPlanRules();
    generate();

    $second = generate();

    expect($second->created)->toBe(0)
        ->and($second->updated)->toBe(0)
        ->and($second->deleted)->toBe(0)
        ->and($second->changed())->toBeFalse()
        ->and(Slot::query()->count())->toBe(32);
});

it('uses the slot horizon setting, or the weeks given', function (): void {
    seedPlanRules();
    app(Settings::class)->flush();
    Setting::query()->create(['key' => SettingKey::SlotHorizonWeeks->value, 'value' => 2]);
    app(Settings::class)->flush();

    expect(generate()->created)->toBe(8)
        ->and(generate(3)->created)->toBe(4);
});

it('skips sessions that have already started today', function (): void {
    seedPlanRules();
    // Saturday 7 November, 12:00: the morning slot has passed.
    $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00', 'Asia/Karachi'));

    generate(1);

    expect(storedStarts()[0])->toBe('2026-11-07 10:00:00');
});

it('respects rule validity dates and ignores inactive rules', function (): void {
    ScheduleRule::factory()->create([
        'day_of_week' => CarbonInterface::SATURDAY,
        'valid_from' => '2026-11-14',
        'valid_to' => '2026-11-21',
    ]);
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SUNDAY, 'is_active' => false]);

    generate(8);

    expect(storedStarts())->toBe(['2026-11-14 03:00:00', '2026-11-21 03:00:00']);
});

it('keeps sessions at the same local time across a DST change (Asia/Karachi 2008)', function (): void {
    // Pakistan observed UTC+6 from 1 June to 31 October 2008.
    $this->travelTo(CarbonImmutable::parse('2008-05-26 09:00', 'Asia/Karachi'));
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'start_time' => '08:00', 'end_time' => '10:00']);
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SUNDAY, 'start_time' => '08:00', 'end_time' => '10:00']);

    generate(1);

    expect(storedStarts())->toBe([
        '2008-05-31 03:00:00', // Saturday, UTC+5
        '2008-06-01 02:00:00', // Sunday, UTC+6
    ]);

    foreach (Slot::query()->get() as $slot) {
        expect($slot->localStartsAt()->format('H:i'))->toBe('08:00')
            ->and($slot->localEndsAt()->format('H:i'))->toBe('10:00');
    }
});

it('keeps sessions at the same local time when DST ends (Asia/Karachi 2008)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2008-10-20 09:00', 'Asia/Karachi'));
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'start_time' => '08:00', 'end_time' => '10:00']);

    generate(2);

    expect(storedStarts())->toBe([
        '2008-10-25 02:00:00', // UTC+6
        '2008-11-01 03:00:00', // back to UTC+5
    ]);
});

it('is DST-safe in any display timezone', function (): void {
    config(['app.display_timezone' => 'Europe/London']);
    // Clocks go back on Sunday 25 October 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'Europe/London'));
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'start_time' => '08:00', 'end_time' => '10:00']);
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SUNDAY, 'start_time' => '08:00', 'end_time' => '10:00']);

    generate(1);

    expect(storedStarts())->toBe([
        '2026-10-24 07:00:00', // BST
        '2026-10-25 08:00:00', // GMT
    ]);
});

it('moves future empty slots when a rule changes', function (): void {
    $rule = ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 6]);
    generate(2);

    $rule->update(['start_time' => '07:30', 'end_time' => '09:30', 'capacity' => 4]);
    $result = generate(2);

    expect($result->created)->toBe(2)
        ->and($result->deleted)->toBe(2)
        ->and(storedStarts())->toBe(['2026-11-07 02:30:00', '2026-11-14 02:30:00'])
        ->and(Slot::query()->pluck('capacity')->unique()->all())->toBe([4]);
});

it('updates capacity and end time of future empty slots in place', function (): void {
    $rule = ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'capacity' => 6]);
    generate(1);
    $slot = Slot::query()->firstOrFail();

    $rule->update(['capacity' => 8, 'end_time' => '10:30']);
    $result = generate(1);

    expect($result->updated)->toBe(1)
        ->and($slot->refresh()->capacity)->toBe(8)
        ->and($slot->localEndsAt()->format('H:i'))->toBe('10:30');
});

it('never changes or removes slots that are in use', function (string $attachment): void {
    $rule = ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'capacity' => 6]);
    generate(2);
    $booked = Slot::query()->orderBy('starts_at')->firstOrFail();

    match ($attachment) {
        'booking' => Booking::factory()->create(['slot_id' => $booked->id]),
        'hold' => BookingHold::factory()->create(['slot_id' => $booked->id]),
        'order item' => OrderItem::factory()->create(['slot_id' => $booked->id]),
    };

    // Capacity change: the slot in use keeps its capacity.
    $rule->update(['capacity' => 3]);
    $result = generate(2);

    expect($booked->refresh()->capacity)->toBe(6)
        ->and($result->kept)->toBe(1)
        ->and($result->updated)->toBe(1);

    // Rule switched off: the empty slot goes, the slot in use stays.
    $rule->update(['is_active' => false]);
    $result = generate(2);

    expect($result->deleted)->toBe(1)
        ->and(Slot::query()->pluck('id')->all())->toBe([$booked->id]);
})->with(['booking', 'hold', 'order item']);

it('never reopens or removes closed and cancelled slots', function (SlotStatus $status): void {
    $rule = ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY]);
    generate(1);
    $slot = Slot::query()->firstOrFail();
    DB::table('slots')->where('id', $slot->id)->update(['status' => $status->value]);

    expect(generate(1)->kept)->toBe(1);

    $rule->update(['is_active' => false]);
    generate(1);

    expect($slot->refresh()->status)->toBe($status);
})->with([SlotStatus::Closed, SlotStatus::Cancelled]);

it('never touches past slots', function (): void {
    $past = Slot::factory()->at(CarbonImmutable::parse('2026-10-31 08:00', 'Asia/Karachi'))->create();

    generate();

    expect($past->fresh())->not->toBeNull();
});

it('lets the older rule win when two rules start at the same time', function (): void {
    $first = ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'capacity' => 6]);
    ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'capacity' => 2]);

    generate(1);

    expect(Slot::query()->count())->toBe(1)
        ->and(Slot::query()->firstOrFail()->schedule_rule_id)->toBe($first->id);
});

it('queues a regeneration when a rule is saved or deleted', function (): void {
    $rule = ScheduleRule::factory()->create();
    Queue::assertPushed(RegenerateSlots::class, 1);

    // Unique job: a second change while one is queued does not queue another.
    $rule->update(['capacity' => 5]);
    Queue::assertPushed(RegenerateSlots::class, 1);

    // The queued run finishes and releases its unique lock.
    Cache::lock('laravel_unique_job:'.RegenerateSlots::class.':')->forceRelease();
    $rule->delete();
    Queue::assertPushed(RegenerateSlots::class, 2);
});

it('runs from the command and reports what changed', function (): void {
    seedPlanRules();

    $this->artisan('slots:generate', ['--weeks' => 1])
        ->expectsOutputToContain('4 created')
        ->assertSuccessful();

    $this->artisan('slots:generate', ['--weeks' => 'abc'])->assertFailed();
});

it('is scheduled daily without overlapping', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'slots:generate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('generates slots when the database is seeded', function (): void {
    $this->seed();

    expect(Slot::query()->count())->toBe(32);
});

it('marks generated slots as rule slots', function (): void {
    seedPlanRules();
    generate(1);

    expect(Slot::query()->pluck('source')->unique()->all())->toBe([SlotSource::Rule]);
});

it('never changes or removes a manual slot at a time no rule produces', function (): void {
    seedPlanRules();
    // Wednesday evening: no rule runs then.
    $manual = Slot::factory()->manual()->at(CarbonImmutable::parse('2026-11-04 17:00', 'Asia/Karachi'), 3)->create(['capacity' => 2]);
    $before = $manual->only(['starts_at', 'ends_at', 'capacity', 'status', 'source', 'updated_at']);

    $result = generate(1);

    expect($result->deleted)->toBe(0)
        ->and($result->created)->toBe(4)
        ->and($manual->refresh()->only(['starts_at', 'ends_at', 'capacity', 'status', 'source', 'updated_at']))->toEqual($before);

    // Every rule switched off: the rule slots go, the manual slot stays.
    ScheduleRule::query()->update(['is_active' => false]);
    $result = generate(1);

    expect($result->deleted)->toBe(4)
        ->and(Slot::query()->pluck('id')->all())->toBe([$manual->id]);
});

it('lets a manual slot win when a rule produces its start time', function (): void {
    $rule = ScheduleRule::factory()->create(['day_of_week' => CarbonInterface::SATURDAY, 'start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 6]);
    $manual = Slot::factory()->manual()->at(CarbonImmutable::parse('2026-11-07 08:00', 'Asia/Karachi'), 3)->create(['capacity' => 2]);

    $result = generate(2);

    expect($result->created)->toBe(1)
        ->and($result->kept)->toBe(1)
        ->and($result->updated)->toBe(0)
        ->and(Slot::query()->count())->toBe(2);

    $manual->refresh();

    expect($manual->source)->toBe(SlotSource::Manual)
        ->and($manual->schedule_rule_id)->toBeNull()
        ->and($manual->capacity)->toBe(2)
        ->and($manual->localEndsAt()->format('H:i'))->toBe('11:00');

    // The rule moves away and back: the manual slot is still never touched.
    $rule->update(['start_time' => '07:00', 'end_time' => '09:00']);
    generate(2);
    $rule->update(['start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 8]);
    generate(2);

    expect($manual->refresh()->capacity)->toBe(2)
        ->and(Slot::query()->where('source', SlotSource::Manual->value)->count())->toBe(1)
        ->and(Slot::query()->count())->toBe(2);
});

it('leaves closed manual slots closed', function (): void {
    $manual = Slot::factory()->manual()->status(SlotStatus::Closed)->at(CarbonImmutable::parse('2026-11-04 17:00', 'Asia/Karachi'))->create();

    generate(1);

    expect($manual->refresh()->status)->toBe(SlotStatus::Closed);
});
