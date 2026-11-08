<?php

declare(strict_types=1);

use App\Actions\Slots\ChangeSlotStatus;
use App\Actions\Slots\GenerateSlots;
use App\Actions\Slots\PreviewScheduleRule;
use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Filament\Resources\ScheduleRules\Pages\CreateScheduleRule;
use App\Filament\Resources\ScheduleRules\Pages\EditScheduleRule;
use App\Models\Booking;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    // Wednesday 11 Nov 2026, 09:00 Pakistan time. Saturdays: 14, 21, 28 Nov ...
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Karachi'));
});

/** @return array<string, mixed> */
function ruleForm(array $overrides = []): array
{
    return [
        'day_of_week' => 6, 'start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 6,
        'valid_from' => null, 'valid_to' => null, 'is_active' => true, ...$overrides,
    ];
}

it('lists the slots a new rule will create, in Pakistan time, without writing anything', function (): void {
    $preview = app(PreviewScheduleRule::class)->handle(ruleForm());

    expect(Slot::query()->count())->toBe(0)
        ->and($preview)->not->toBeNull()
        ->and($preview->new)->toHaveCount($preview->weeks)
        ->and($preview->new[0]->label())->toBe('Sat 14 Nov 2026, 08:00–10:00')
        ->and($preview->changed)->toBe([])
        ->and($preview->removed)->toBe([])
        ->and($preview->kept)->toBe([]);
});

it('respects the valid from and until dates for seasonal rules', function (): void {
    $preview = app(PreviewScheduleRule::class)->handle(ruleForm(['valid_from' => '2026-11-20', 'valid_to' => '2026-11-30']));

    expect(array_map(fn ($i) => $i->label(), $preview->new))->toBe([
        'Sat 21 Nov 2026, 08:00–10:00',
        'Sat 28 Nov 2026, 08:00–10:00',
    ]);
});

it('shows moved, changed, removed and kept slots when editing a rule', function (): void {
    $rule = ScheduleRule::factory()->create(['day_of_week' => 6, 'start_time' => '08:00:00', 'end_time' => '10:00:00', 'capacity' => 6, 'is_active' => true]);
    app(GenerateSlots::class)->handle(3); // 14, 21, 28 Nov at 08:00

    $slots = Slot::query()->orderBy('starts_at')->get();
    Booking::factory()->status(BookingStatus::Confirmed)->create(['slot_id' => $slots[0]->id]);
    app(ChangeSlotStatus::class)->handle($slots[1], SlotStatus::Closed);

    // Same start, longer and bigger: empty open slots change, the others stay.
    $changed = app(PreviewScheduleRule::class)->handle(ruleForm(['end_time' => '10:30', 'capacity' => 8]), $rule);

    expect(array_map(fn ($i) => $i->label(), $changed->changed))->toContain('Sat 28 Nov 2026, 08:00–10:30')
        ->and(collect($changed->kept)->pluck('note')->all())->toContain('has bookings', 'closed')
        ->and(collect($changed->kept)->firstWhere('note', 'has bookings')->seatsBooked)->toBe(1);

    // A new start time: empty slots move (removed + new), the booked one keeps its time.
    $moved = app(PreviewScheduleRule::class)->handle(ruleForm(['start_time' => '09:00', 'end_time' => '11:00']), $rule);

    expect(collect($moved->removed)->map->label()->all())->toContain('Sat 28 Nov 2026, 08:00–10:00')
        ->and(collect($moved->new)->map->label()->all())->toContain('Sat 14 Nov 2026, 09:00–11:00')
        ->and(collect($moved->kept)->map->label()->all())->toContain('Sat 14 Nov 2026, 08:00–10:00');

    expect(Slot::query()->count())->toBe($slots->count());
});

it('shows switching a rule off as removals', function (): void {
    // Saving the rule already generated its slots over the whole horizon.
    $rule = ScheduleRule::factory()->create(['day_of_week' => 6, 'start_time' => '08:00:00', 'end_time' => '10:00:00', 'is_active' => true]);

    $preview = app(PreviewScheduleRule::class)->handle(ruleForm(['is_active' => false]), $rule);

    expect($preview->removed)->toHaveCount($preview->weeks)->and($preview->new)->toBe([]);
});

it('keeps a manual slot that sits where the rule wants one', function (): void {
    Slot::factory()->manual()->at(CarbonImmutable::parse('2026-11-14 08:00', 'Asia/Karachi'))->create();

    $preview = app(PreviewScheduleRule::class)->handle(ruleForm());

    expect(collect($preview->kept)->pluck('note')->all())->toBe(['manual slot'])
        ->and(collect($preview->new)->map->label()->all())->not->toContain('Sat 14 Nov 2026, 08:00–10:00');
});

it('waits for a complete form', function (): void {
    expect(app(PreviewScheduleRule::class)->handle(ruleForm(['start_time' => null])))->toBeNull()
        ->and(app(PreviewScheduleRule::class)->handle(ruleForm(['end_time' => '07:00'])))->toBeNull();
});

it('shows the preview in the rule form', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->admin()->withMfa()->create());

    Livewire::test(CreateScheduleRule::class)
        ->fillForm(ruleForm())
        ->assertSee('New slots')
        ->assertSee('Sat 14 Nov 2026, 08:00–10:00');

    $rule = ScheduleRule::factory()->create(['day_of_week' => 0, 'start_time' => '15:00:00', 'end_time' => '17:00:00', 'is_active' => true]);

    Livewire::test(EditScheduleRule::class, ['record' => $rule->id])
        ->assertSee('No change to the slots');
});
