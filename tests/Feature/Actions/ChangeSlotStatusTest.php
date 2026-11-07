<?php

declare(strict_types=1);

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\SlotStatus;
use App\Events\SlotCancelled;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

it('closes and reopens a slot, logging each change', function (): void {
    $slot = Slot::factory()->create();
    $admin = User::factory()->admin()->create();

    app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Closed, $admin);
    expect($slot->refresh()->status)->toBe(SlotStatus::Closed);

    app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Open, $admin);
    expect($slot->refresh()->status)->toBe(SlotStatus::Open);

    $logs = Activity::query()->where('log_name', 'slots')->where('subject_id', $slot->id)->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->properties->only(['from', 'to'])->all())->toBe(['from' => 'open', 'to' => 'closed'])
        ->and($logs[1]->causer_id)->toBe($admin->id);
});

it('cancels with a reason, records who and when, and announces it', function (SlotStatus $from): void {
    Event::fake([SlotCancelled::class]);
    $this->freezeTime();
    $slot = Slot::factory()->status($from)->create();
    $admin = User::factory()->admin()->create();

    app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Cancelled, $admin, '  Trainer unwell  ');

    $slot->refresh();

    expect($slot->status)->toBe(SlotStatus::Cancelled)
        ->and($slot->cancel_reason)->toBe('Trainer unwell')
        ->and($slot->cancelled_by)->toBe($admin->id)
        ->and($slot->cancelled_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    Event::assertDispatched(SlotCancelled::class, fn (SlotCancelled $event): bool => $event->slot->is($slot));
})->with([SlotStatus::Open, SlotStatus::Closed]);

it('refuses to cancel without a reason', function (?string $reason): void {
    Event::fake([SlotCancelled::class]);
    $slot = Slot::factory()->create();

    expect(fn () => app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Cancelled, null, $reason))
        ->toThrow(InvalidStatusTransition::class, 'needs a reason');

    expect($slot->refresh()->status)->toBe(SlotStatus::Open);
    Event::assertNotDispatched(SlotCancelled::class);
})->with([null, '', '   ']);

it('never changes a cancelled slot', function (SlotStatus $to): void {
    $slot = Slot::factory()->status(SlotStatus::Cancelled)->create();

    expect(fn () => app(ChangeSlotStatus::class)->handle($slot, $to, null, 'Changed my mind'))
        ->toThrow(InvalidStatusTransition::class);

    expect($slot->refresh()->status)->toBe(SlotStatus::Cancelled)
        ->and(Activity::query()->where('log_name', 'slots')->exists())->toBeFalse();
})->with([SlotStatus::Open, SlotStatus::Closed, SlotStatus::Cancelled]);

it('refuses a no-op change', function (): void {
    $slot = Slot::factory()->create();

    app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Open);
})->throws(InvalidStatusTransition::class);
