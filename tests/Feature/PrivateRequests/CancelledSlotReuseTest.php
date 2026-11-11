<?php

declare(strict_types=1);

use App\Actions\Booking\FulfilOrder;
use App\Actions\Booking\ReleaseExpiredHolds;
use App\Actions\PrivateRequests\ApprovePrivateRequest;
use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Enums\PrivateRequestStatus;
use App\Enums\SlotSource;
use App\Enums\SlotStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\PrivateRequestRejected;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\PrivateSessionRequest;
use App\Models\ScheduleRule;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/PrivateRequestHelpers.php';

/*
| Prompt 15B follow-up: slot starts are unique, so a cancelled slot at the
| requested start is reopened as the private-only slot instead of refusing
| the approval, as long as nothing is left on it. Open and closed slots at
| that start still refuse.
*/

beforeEach(fn () => Notification::fake());

/** A slot at the request's start (10:00 Pakistan time, 3 days ahead). */
function prSlotAtRequestStart(array $attributes = []): Slot
{
    return Slot::factory()->at(prStart(3, '10:00')->setTimezone((string) config('app.display_timezone')))->create(['capacity' => 4, ...$attributes]);
}

function prCancel(Slot $slot, string $reason = 'Rain'): Slot
{
    return app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Cancelled, prAdmin(), $reason);
}

it('reopens an empty cancelled slot at the same start as the private-only slot', function (): void {
    $trainer = User::factory()->trainer()->create();
    $cancelled = prCancel(prSlotAtRequestStart(['ends_at' => prStart(3, '11:00')]));

    $request = prRequest(start: prStart(3, '10:00'), riders: 2);
    $approved = prApprove($request, $trainer);
    $slot = $cancelled->fresh();

    expect($approved->status)->toBe(PrivateRequestStatus::Approved)
        ->and($approved->slot_id)->toBe($cancelled->id)
        ->and(Slot::query()->count())->toBe(1)
        ->and($slot?->status)->toBe(SlotStatus::Open)
        ->and($slot?->private_only)->toBeTrue()
        ->and($slot?->source)->toBe(SlotSource::Manual)
        ->and($slot?->ends_at->equalTo($request->ends_at))->toBeTrue() // the request's 2 hours, not the old 1
        ->and($slot?->capacity)->toBe(6) // riders-per-slot setting
        ->and($slot?->trainer_id)->toBe($trainer->id)
        ->and($slot?->cancel_reason)->toBeNull()
        ->and($slot?->cancelled_at)->toBeNull()
        ->and(BookingHold::query()->where('slot_id', $cancelled->id)->sole()->is_private)->toBeTrue()
        ->and(Order::query()->sole()->status)->toBe(OrderStatus::PendingPayment);

    $log = Activity::query()->where('event', 'reopened_for_private_request')->sole();

    expect($log->getExtraProperty('request'))->toBe($request->reference)
        ->and($log->getExtraProperty('old')['cancel_reason'])->toBe('Rain');
});

it('detaches a reused cancelled rule slot from its schedule rule', function (): void {
    $rule = ScheduleRule::factory()->create(['is_active' => false]); // inactive: generates no slots of its own
    $cancelled = prCancel(prSlotAtRequestStart(['schedule_rule_id' => $rule->id, 'source' => SlotSource::Rule]));

    prApprove(prRequest(start: prStart(3, '10:00')));

    expect($cancelled->fresh()?->source)->toBe(SlotSource::Manual)
        ->and($cancelled->fresh()?->schedule_rule_id)->toBeNull();
});

it('books and pays on the reused slot through FulfilOrder', function (): void {
    $cancelled = prCancel(prSlotAtRequestStart());
    // An old cancelled booking stays cancelled and doesn't count.
    Booking::factory()->status(BookingStatus::Cancelled)->create(['slot_id' => $cancelled->id]);

    $request = prApprove(prRequest(start: prStart(3, '10:00')));
    app(FulfilOrder::class)->handle($request->order);

    expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Paid)
        ->and(Booking::query()->where('slot_id', $cancelled->id)->where('status', BookingStatus::Confirmed)->count())->toBe(1);
});

it('lets the same start be requested and approved again after an unpaid approval lapsed', function (): void {
    $first = prApprove(prRequest(start: prStart(3, '10:00')));
    $slotId = $first->slot_id;

    $this->travelTo($first->pay_by);
    app(ReleaseExpiredHolds::class)->handle();
    expect(Slot::query()->findOrFail($slotId)->status)->toBe(SlotStatus::Cancelled);

    $second = prApprove(prRequest(start: prStart(3, '10:00')));

    expect($second->status)->toBe(PrivateRequestStatus::Approved)
        ->and($second->slot_id)->toBe($slotId)
        ->and(Slot::query()->count())->toBe(1);
});

it('still refuses an open or closed slot at that start', function (SlotStatus $status): void {
    $slot = prSlotAtRequestStart();

    if ($status === SlotStatus::Closed) {
        app(ChangeSlotStatus::class)->handle($slot, SlotStatus::Closed, prAdmin());
    }

    // Created before the slot would clash at request time, so add the request directly.
    $request = PrivateSessionRequest::factory()->create(['starts_at' => prStart(3, '10:00'), 'ends_at' => prStart(3, '12:00')]);

    expect(fn () => prApprove($request))->toThrow(PrivateRequestRejected::class, 'A '.mb_strtolower($status->label()).' slot already starts at');
    expect($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested)
        ->and($slot->fresh()?->status)->toBe($status)
        ->and(Order::query()->count())->toBe(0);
})->with([SlotStatus::Open, SlotStatus::Closed]);

it('refuses a cancelled slot that still has a booking or an active hold on it', function (string $what): void {
    $cancelled = prCancel(prSlotAtRequestStart());

    // Defensive: the slot cancellation normally clears these.
    if ($what === 'booking') {
        DB::table('bookings')->insert(Booking::factory()->make(['slot_id' => $cancelled->id])->getAttributes() + ['created_at' => now(), 'updated_at' => now()]);
    } else {
        BookingHold::factory()->create(['slot_id' => $cancelled->id, 'seats' => 1]);
    }

    $request = prRequest(start: prStart(3, '10:00'));

    expect(fn () => prApprove($request))->toThrow(PrivateRequestRejected::class, 'still has bookings or holds');
    expect($cancelled->fresh()?->status)->toBe(SlotStatus::Cancelled)
        ->and($request->fresh()?->status)->toBe(PrivateRequestStatus::Requested);
})->with(['booking', 'hold']);

it('never reopens a slot that isn’t cancelled, or one that has started', function (): void {
    $open = prSlotAtRequestStart();

    expect(fn () => DB::transaction(fn () => app(ChangeSlotStatus::class)->reopenForPrivateRequest($open, $open->ends_at, 6, null, prAdmin(), 'RQ-TEST01')))
        ->toThrow(InvalidStatusTransition::class);

    $past = prCancel(Slot::factory()->at(now()->toImmutable()->setTimezone((string) config('app.display_timezone'))->subDay()->setTime(10, 0))->create());

    expect(fn () => DB::transaction(fn () => app(ChangeSlotStatus::class)->reopenForPrivateRequest($past, $past->ends_at, 6, null, prAdmin(), 'RQ-TEST02')))
        ->toThrow(InvalidStatusTransition::class, 'already started');
});

it('shows no problem on the admin page for a reusable cancelled slot', function (): void {
    prCancel(prSlotAtRequestStart());
    $request = prRequest(start: prStart(3, '10:00'));

    expect(app(ApprovePrivateRequest::class)->problem($request))->toBeNull();
});
