<?php

declare(strict_types=1);

use App\Actions\Settings\UpdateSettings;
use App\Actions\Slots\BulkSchedule\ApplyBulkSchedule;
use App\Actions\Slots\BulkSchedule\BulkScheduleCriteria;
use App\Actions\Slots\BulkSchedule\BulkScheduleItem;
use App\Actions\Slots\BulkSchedule\PreviewBulkSchedule;
use App\Actions\Slots\GenerateSlots;
use App\Actions\Slots\SlotOverlap;
use App\Enums\BookingStatus;
use App\Enums\SettingKey;
use App\Enums\SlotChangeResponse;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Enums\WaitlistStatus;
use App\Models\BookingHold;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\SlotChangeNotice;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\ScheduleChangedSummary;
use App\Notifications\SlotTimeChanged;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/BulkScheduleHelpers.php';

beforeEach(fn () => Notification::fake());

describe('overlap check', function (): void {
    it('refuses times within the buffer of another session and ignores cancelled slots and the slot itself', function (): void {
        $slot = nearSlot(5, 8); // 08:00–10:00 Pakistan time
        $overlap = app(SlotOverlap::class);
        $at = fn (int $h, int $m = 0): CarbonImmutable => $slot->localStartsAt()->setTime($h, $m)->utc();

        expect($overlap->bufferMinutes())->toBe(30)
            ->and($overlap->firstClash($at(10, 15), $at(12, 15))?->id)->toBe($slot->id)
            ->and($overlap->firstClash($at(10, 30), $at(12, 30)))->toBeNull()
            ->and($overlap->firstClash($at(5, 30), $at(7, 30)))->toBeNull()
            ->and($overlap->firstClash($at(5, 45), $at(7, 45))?->id)->toBe($slot->id)
            ->and($overlap->firstClash($at(9), $at(11), [$slot->id]))->toBeNull();

        $slot->status = SlotStatus::Cancelled;
        $slot->save();

        expect($overlap->firstClash($at(9), $at(11)))->toBeNull();
    });

    it('uses the buffer setting', function (): void {
        $slot = nearSlot(5, 8);
        app(UpdateSettings::class)->handle([SettingKey::SlotBufferMinutes->value => 0]);

        expect(app(SlotOverlap::class)->firstClash($slot->localStartsAt()->setTime(10, 0)->utc(), $slot->localStartsAt()->setTime(12, 0)->utc()))->toBeNull();
    });
});

describe('preview', function (): void {
    it('saves nothing and lists what would change', function (): void {
        $slot = nearSlot(5, 8);
        bsCreditBooking($slot);

        $preview = app(PreviewBulkSchedule::class)->handle(bsCriteria($slot, 'update', ['start_time' => '07:00', 'end_time' => '09:00', 'capacity' => 8]));

        expect($preview->actionable())->toHaveCount(1)
            ->and($preview->items[0]->kind)->toBe(BulkScheduleItem::UPDATE)
            ->and($preview->items[0]->newLabel())->toContain('07:00–09:00')
            ->and($preview->items[0]->newCapacity)->toBe(8)
            ->and($preview->items[0]->seatsBooked)->toBe(1)
            ->and($preview->ridersAffected())->toBe(1)
            ->and($slot->fresh()?->localStartsAt()->format('H:i'))->toBe('08:00')
            ->and(SlotChangeNotice::query()->count())->toBe(0);
    });

    it('skips new slots at a taken start or too close to another session', function (): void {
        $slot = nearSlot(5, 8);
        $criteria = fn (string $start, string $end) => bsCriteria($slot, 'create', ['start_time' => $start, 'end_time' => $end, 'capacity' => 6]);

        $taken = app(PreviewBulkSchedule::class)->handle($criteria('08:00', '10:00'))->items[0];
        $close = app(PreviewBulkSchedule::class)->handle($criteria('10:15', '12:15'))->items[0];
        $fine = app(PreviewBulkSchedule::class)->handle($criteria('15:00', '17:00'))->items[0];

        expect($taken->kind)->toBe(BulkScheduleItem::SKIP)->and($taken->note)->toContain('already starts')
            ->and($close->kind)->toBe(BulkScheduleItem::SKIP)->and($close->note)->toContain('Within 30 min')
            ->and($fine->kind)->toBe(BulkScheduleItem::CREATE);
    });

    it('refuses a capacity below the seats taken', function (): void {
        $slot = nearSlot(5, 8, ['capacity' => 6]);
        bsCreditBooking($slot);
        bsCreditBooking($slot);
        BookingHold::factory()->create(['slot_id' => $slot->id, 'seats' => 1]);

        $item = app(PreviewBulkSchedule::class)->handle(bsCriteria($slot, 'update', ['capacity' => 2]))->items[0];

        expect($item->kind)->toBe(BulkScheduleItem::SKIP)->and($item->note)->toContain('3 seats taken');
    });

    it('keeps the time of a slot with a hold but still changes its capacity', function (): void {
        $slot = nearSlot(5, 8, ['capacity' => 6]);
        BookingHold::factory()->create(['slot_id' => $slot->id, 'seats' => 1]);

        $criteria = bsCriteria($slot, 'update', ['start_time' => '07:00', 'end_time' => '09:00', 'capacity' => 8]);
        $item = app(PreviewBulkSchedule::class)->handle($criteria)->items[0];

        expect($item->kind)->toBe(BulkScheduleItem::UPDATE)
            ->and($item->changesTime())->toBeFalse()
            ->and($item->note)->toContain('Time not changed');

        bsApply($criteria);

        expect($slot->fresh()?->capacity)->toBe(8)
            ->and($slot->fresh()?->localStartsAt()->format('H:i'))->toBe('08:00');
    });

    it('matches only the chosen weekday, start time and slot type', function (): void {
        $morning = nearSlot(5, 8);
        $afternoon = nearSlot(5, 15);
        nearSlot(6, 8);
        Slot::factory()->manual()->at($morning->localStartsAt()->setTime(18, 0))->create(['private_only' => true]);

        $items = app(PreviewBulkSchedule::class)->handle(bsCriteria($morning, 'close', ['match_start' => '08:00', 'type' => 'group']))->items;

        expect(array_map(fn (BulkScheduleItem $i): ?int => $i->slotId, $items))->toBe([$morning->id])
            ->and($items[0]->slotId)->not->toBe($afternoon->id);
    });
});

describe('apply', function (): void {
    it('creates slots for every matching date and logs the batch', function (): void {
        $from = CarbonImmutable::now((string) config('app.display_timezone'))->addDays(2)->startOfDay();
        $trainer = User::factory()->trainer()->create();

        $result = bsApply(BulkScheduleCriteria::fromForm([
            'operation' => 'create',
            'from' => $from->toDateString(),
            'until' => $from->addDays(13)->toDateString(),
            'days' => [$from->dayOfWeek],
            'start_time' => '16:00',
            'end_time' => '18:00',
            'capacity' => 4,
            'trainer_id' => $trainer->id,
            'type' => 'private',
        ]));

        $slots = Slot::query()->orderBy('starts_at')->get();

        expect($result->created)->toBe(2)
            ->and($slots)->toHaveCount(2)
            ->and($slots->every(fn (Slot $s): bool => $s->isManual() && $s->private_only && $s->capacity === 4 && $s->trainer_id === $trainer->id))->toBeTrue()
            ->and($slots[0]->localStartsAt()->format('H:i'))->toBe('16:00')
            ->and(Activity::query()->where('event', 'bulk_applied')->sole()->getExtraProperty('batch'))->toBe($result->batch)
            ->and(Activity::query()->where('event', 'bulk_created')->count())->toBe(2);

        Notification::assertSentTo($trainer, ScheduleChangedSummary::class);
    });

    it('moves a booked slot, bumps the calendar sequence and emails each customer once with options', function (): void {
        $trainer = User::factory()->trainer()->create();
        $slot = nearSlot(5, 8, ['trainer_id' => $trainer->id]);
        $credit = bsCreditBooking($slot);
        $paid = bsPaidBooking($slot);
        $second = bsPaidBooking($slot, $paid->user); // same customer, two riders

        $result = bsApply(bsCriteria($slot, 'update', ['start_time' => '07:00', 'end_time' => '09:00']));

        $slot->refresh();

        expect($result->updated)->toBe(1)
            ->and($result->customersNotified)->toBe(2)
            ->and($result->trainersNotified)->toBe(1)
            ->and($slot->localStartsAt()->format('H:i'))->toBe('07:00')
            ->and($credit->fresh()?->calendar_sequence)->toBe(1)
            ->and($second->fresh()?->calendar_sequence)->toBe(1)
            ->and(SlotChangeNotice::query()->count())->toBe(2);

        $notice = SlotChangeNotice::query()->where('user_id', $paid->user_id)->sole();

        expect($notice->status)->toBe(SlotChangeResponse::Pending)
            ->and($notice->oldLabel())->toContain('08:00–10:00')
            ->and($notice->newLabel())->toContain('07:00–09:00');

        Notification::assertSentToTimes($paid->user, SlotTimeChanged::class, 1);
        Notification::assertSentToTimes($credit->user, SlotTimeChanged::class, 1);
        Notification::assertSentTo($trainer, ScheduleChangedSummary::class, fn (ScheduleChangedSummary $n): bool => str_contains($n->lines[0], 'moved to'));

        $mail = (new SlotTimeChanged($notice))->toMail($paid->user);
        $html = (string) $mail->render();

        expect($html)->toContain('Keep the new time')
            ->and($html)->toContain('Move to another session')
            ->and($html)->toContain('schedule-change/'.$notice->id)
            ->and($mail->rawAttachments)->toHaveCount(1);
    });

    it('detaches a moved rule slot so the generator does not refill the old time', function (): void {
        $local = CarbonImmutable::now((string) config('app.display_timezone'))->next(CarbonImmutable::SATURDAY)->addWeek();
        ScheduleRule::factory()->create(['day_of_week' => 6, 'start_time' => '08:00:00', 'end_time' => '10:00:00', 'capacity' => 6]);
        app(GenerateSlots::class)->handle(3);

        $slot = Slot::query()->where('starts_at', $local->setTime(8, 0)->utc())->sole();
        $old = $slot->starts_at;

        bsApply(bsCriteria($slot, 'update', ['start_time' => '07:00', 'end_time' => '09:00', 'capacity' => 4]));
        $slot->refresh();

        expect($slot->source)->toBe(SlotSource::Manual)
            ->and($slot->schedule_rule_id)->toBeNull()
            ->and($slot->replaces_starts_at?->equalTo($old))->toBeTrue();

        app(GenerateSlots::class)->handle(3);

        expect(Slot::query()->where('starts_at', $old)->exists())->toBeFalse()
            ->and($slot->fresh()?->capacity)->toBe(4);
    });

    it('reports a slot that changed after the preview and applies the rest', function (): void {
        $a = nearSlot(5, 8);
        $b = nearSlot(12, 8);
        $criteria = BulkScheduleCriteria::fromForm([
            'operation' => 'update',
            'from' => $a->localStartsAt()->toDateString(),
            'until' => $b->localStartsAt()->toDateString(),
            'days' => [$a->localStartsAt()->dayOfWeek],
            'start_time' => '07:00',
            'end_time' => '09:00',
        ]);

        $preview = app(PreviewBulkSchedule::class)->handle($criteria);
        expect($preview->actionable())->toHaveCount(2);

        // A card hold lands on slot A between preview and apply.
        BookingHold::factory()->create(['slot_id' => $a->id, 'seats' => 1]);

        $result = app(ApplyBulkSchedule::class)->handle($criteria, $preview->actionable(), User::factory()->admin()->create());

        expect($result->updated)->toBe(1)
            ->and($result->failures)->toHaveCount(1)
            ->and($result->failures[0])->toContain('changed since the preview')
            ->and($a->fresh()?->localStartsAt()->format('H:i'))->toBe('08:00')
            ->and($b->fresh()?->localStartsAt()->format('H:i'))->toBe('07:00');
    });

    it('closes slots and keeps their bookings', function (): void {
        $slot = nearSlot(5, 8);
        $booking = bsCreditBooking($slot);

        $result = bsApply(bsCriteria($slot, 'close'));

        expect($result->closed)->toBe(1)
            ->and($slot->fresh()?->status)->toBe(SlotStatus::Closed)
            ->and($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });

    it('cancels slots through the usual slot cancellation', function (): void {
        $slot = nearSlot(5, 8);
        $booking = bsCreditBooking($slot);

        $result = bsApply(bsCriteria($slot, 'cancel', ['reason' => 'Arena works']));

        expect($result->cancelled)->toBe(1)
            ->and($result->customersNotified)->toBe(1)
            ->and($slot->fresh()?->status)->toBe(SlotStatus::Cancelled)
            ->and($slot->fresh()?->cancel_reason)->toBe('Arena works')
            ->and($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
            ->and($booking->creditPack?->fresh()?->credits_used)->toBe(0);
    });

    it('needs a reason to cancel and an admin to apply', function (): void {
        $slot = nearSlot(5, 8);

        expect(fn () => bsCriteria($slot, 'cancel'))->toThrow(InvalidArgumentException::class, 'reason');
        expect(fn () => bsApply(bsCriteria($slot, 'close'), User::factory()->staff()->create()))->toThrow(InvalidArgumentException::class);
    });

    it('offers new seats to the waitlist when capacity goes up', function (): void {
        $slot = nearSlot(5, 8, ['capacity' => 1]);
        bsCreditBooking($slot);
        $entry = WaitlistEntry::factory()->create(['slot_id' => $slot->id, 'seats' => 1]);

        bsApply(bsCriteria($slot, 'update', ['capacity' => 2]));

        expect($entry->fresh()?->status)->toBe(WaitlistStatus::Offered);
    });
});

it('keeps the buffer setting with the booking rules, default 30 minutes', function (): void {
    expect(SettingKey::SlotBufferMinutes->group())->toBe('booking')
        ->and(app(Settings::class)->int(SettingKey::SlotBufferMinutes))->toBe(30);
});
