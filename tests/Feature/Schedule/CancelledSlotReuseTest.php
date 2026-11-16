<?php

declare(strict_types=1);

use App\Actions\Slots\BulkSchedule\BulkScheduleItem;
use App\Actions\Slots\BulkSchedule\PreviewBulkSchedule;
use App\Actions\Slots\ChangeSlotStatus;
use App\Actions\Slots\GenerateSlots;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Models\BookingHold;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/BulkScheduleHelpers.php';

/*
| Slot starts are unique, so a cancelled slot used to block its start time
| for good. The bulk editor's create (through CreateManualSlot) now reopens
| an empty future cancelled slot at the same start with the new details.
| The generator still leaves cancelled slots alone (decided 2026-11-16).
*/

beforeEach(fn () => Notification::fake());

function reuseCancel(Slot $slot): Slot
{
    return app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Cancelled, User::factory()->admin()->create(), 'Withdrawn');
}

it('previews a create at an empty cancelled slot as a create that reuses it', function (): void {
    $slot = reuseCancel(nearSlot(5, 8, ['private_only' => true]));

    $item = app(PreviewBulkSchedule::class)->handle(bsCriteria($slot, 'create', ['start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 6]))->items[0];

    expect($item->kind)->toBe(BulkScheduleItem::CREATE)
        ->and($item->note)->toContain('Reuses a cancelled slot');
});

it('reopens the cancelled private-only slot as a group slot with the new details', function (): void {
    $trainer = User::factory()->trainer()->create();
    $slot = reuseCancel(nearSlot(5, 8, ['private_only' => true, 'capacity' => 2]));

    $result = bsApply(bsCriteria($slot, 'create', ['start_time' => '08:00', 'end_time' => '09:30', 'capacity' => 6, 'trainer_id' => $trainer->id, 'type' => 'group']));
    $fresh = $slot->fresh();

    expect($result->created)->toBe(1)
        ->and($result->failures)->toBe([])
        ->and(Slot::query()->count())->toBe(1)
        ->and($fresh?->status)->toBe(SlotStatus::Open)
        ->and($fresh?->source)->toBe(SlotSource::Manual)
        ->and($fresh?->private_only)->toBeFalse()
        ->and($fresh?->capacity)->toBe(6)
        ->and($fresh?->trainer_id)->toBe($trainer->id)
        ->and($fresh?->localEndsAt()->format('H:i'))->toBe('09:30')
        ->and($fresh?->cancel_reason)->toBeNull()
        ->and($fresh?->cancelled_at)->toBeNull();

    $log = Activity::query()->where('event', 'reopened_as_new_slot')->sole();
    expect($log->properties['old']['cancel_reason'])->toBe('Withdrawn')
        ->and($log->properties['old']['private_only'])->toBeTrue();
});

it('skips a cancelled slot that still has an active hold', function (): void {
    $slot = reuseCancel(nearSlot(5, 8));
    BookingHold::factory()->create(['slot_id' => $slot->id, 'seats' => 1]);

    $item = app(PreviewBulkSchedule::class)->handle(bsCriteria($slot, 'create', ['start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 6]))->items[0];

    expect($item->kind)->toBe(BulkScheduleItem::SKIP)
        ->and($item->note)->toContain('still has bookings or holds')
        ->and($slot->fresh()?->status)->toBe(SlotStatus::Cancelled);
});

it('still skips an open or closed slot at the same start', function (SlotStatus $status): void {
    $slot = nearSlot(5, 8);

    if ($status === SlotStatus::Closed) {
        app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Closed);
    }

    $item = app(PreviewBulkSchedule::class)->handle(bsCriteria($slot, 'create', ['start_time' => '08:00', 'end_time' => '10:00', 'capacity' => 6]))->items[0];

    expect($item->kind)->toBe(BulkScheduleItem::SKIP)->and($item->note)->toContain('already starts');
})->with([SlotStatus::Open, SlotStatus::Closed]);

it('does not let the generator refill a cancelled rule slot', function (): void {
    $rule = ScheduleRule::factory()->create();
    app(GenerateSlots::class)->handle();
    $slot = Slot::query()->where('schedule_rule_id', $rule->id)->orderBy('starts_at')->firstOrFail();
    reuseCancel($slot);

    app(GenerateSlots::class)->handle();

    expect($slot->fresh()?->status)->toBe(SlotStatus::Cancelled)
        ->and(Slot::query()->where('starts_at', $slot->starts_at)->count())->toBe(1);
});
