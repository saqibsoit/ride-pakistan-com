<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\SlotChangeResponse;
use App\Models\Slot;
use App\Models\SlotChangeNotice;
use App\Notifications\BookingCancelled;
use App\Notifications\BookingMoved;
use App\Support\SlotChangeLink;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/BulkScheduleHelpers.php';

beforeEach(fn () => Notification::fake());

/** Moves the slot to 07:00–09:00 through the bulk editor and returns the customer's notice. */
function bsMovedNotice(Slot $slot, int $userId): SlotChangeNotice
{
    bsApply(bsCriteria($slot, 'update', ['start_time' => '07:00', 'end_time' => '09:00']));

    return SlotChangeNotice::query()->where('user_id', $userId)->sole();
}

it('shows the choices behind a signed link, refuses a tampered one and explains an expired one', function (): void {
    $slot = nearSlot(5, 8);
    $booking = bsCreditBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->get(SlotChangeLink::show($notice))
        ->assertOk()
        ->assertSee('Your session has a new time')
        ->assertSee('07:00–09:00')
        ->assertSee('Keep the new time')
        ->assertSee($booking->ticket_code)
        ->assertHeader('X-Robots-Tag');

    $this->get(route('slot-change.show', $notice))->assertForbidden();
    $this->get(SlotChangeLink::show($notice).'x')->assertForbidden();

    $link = SlotChangeLink::show($notice);
    $this->travelTo($notice->new_starts_at->addMinute());

    $this->get($link)->assertStatus(410)->assertSee('This link has expired');
});

it('keeps the new time, once', function (): void {
    $slot = nearSlot(5, 8);
    $booking = bsCreditBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->post(SlotChangeLink::accept($notice))->assertOk()->assertSee('You’re keeping the new time');

    expect($notice->fresh()?->status)->toBe(SlotChangeResponse::Accepted)
        ->and($booking->fresh()?->slot_id)->toBe($slot->id)
        ->and(Activity::query()->where('event', 'slot_change_answered')->count())->toBe(1);

    $this->post(SlotChangeLink::cancel($notice))->assertOk()->assertSee('You’ve already answered');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
});

it('moves a credit booking to another session', function (): void {
    $slot = nearSlot(5, 8);
    $target = nearSlot(6, 15);
    $booking = bsCreditBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->get(SlotChangeLink::move($notice))->assertOk()->assertSee('value="'.$target->id.'"', false);

    $this->post(SlotChangeLink::move($notice), ['slot_id' => $target->id])->assertOk()->assertSee('Your booking has moved')->assertSee('spam');

    expect($booking->fresh()?->slot_id)->toBe($target->id)
        ->and($notice->fresh()?->status)->toBe(SlotChangeResponse::Moved);

    Notification::assertSentTo($booking->user, BookingMoved::class);
});

it('moves every paid booking of the customer together, even inside the cutoff', function (): void {
    $slot = nearSlot(1, 8); // inside the 48-hour cutoff
    $target = nearSlot(6, 15);
    $first = bsPaidBooking($slot);
    $second = bsPaidBooking($slot, $first->user);
    $notice = bsMovedNotice($slot, $first->user_id);

    $this->post(SlotChangeLink::move($notice), ['slot_id' => $target->id])->assertOk();

    expect($first->fresh()?->slot_id)->toBe($target->id)
        ->and($second->fresh()?->slot_id)->toBe($target->id);
});

it('refuses a move to a slot without room', function (): void {
    $slot = nearSlot(5, 8);
    $full = nearSlot(6, 15, ['capacity' => 1]);
    bsFreeBooking($full);
    $booking = bsCreditBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->get(SlotChangeLink::move($notice))->assertOk()->assertDontSee('value="'.$full->id.'"', false);

    $this->from(SlotChangeLink::move($notice))
        ->post(SlotChangeLink::move($notice), ['slot_id' => $full->id])
        ->assertRedirect()
        ->assertSessionHasErrors('slot_id');

    expect($booking->fresh()?->slot_id)->toBe($slot->id)
        ->and($notice->fresh()?->status)->toBe(SlotChangeResponse::Pending);
});

it('cancels a credit booking with the credit back, even after the cutoff', function (): void {
    $slot = nearSlot(1, 8);
    $booking = bsCreditBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->post(SlotChangeLink::cancel($notice))->assertOk()->assertSee('Your booking is cancelled');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->creditPack?->fresh()?->credits_used)->toBe(0)
        ->and($notice->fresh()?->status)->toBe(SlotChangeResponse::Cancelled);

    Notification::assertSentTo($booking->user, BookingCancelled::class);
});

it('keeps a paid booking and flags its order when the customer asks for a refund', function (): void {
    $slot = nearSlot(5, 8);
    $booking = bsPaidBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->get(SlotChangeLink::show($notice))->assertSee('Ask for a refund');
    $this->post(SlotChangeLink::cancel($notice))->assertOk()->assertSee('We’ve got your refund request');

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->order?->fresh()?->meta['needs_review'] ?? null)->toContain('asked for a refund')
        ->and($notice->fresh()?->status)->toBe(SlotChangeResponse::RefundRequested);
});

it('cancels a no-charge booking', function (): void {
    $slot = nearSlot(5, 8);
    $booking = bsFreeBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->post(SlotChangeLink::cancel($notice))->assertOk();

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled);
});

it('leaves bookings at the new time when nobody answers', function (): void {
    $slot = nearSlot(5, 8);
    $booking = bsCreditBooking($slot);
    $notice = bsMovedNotice($slot, $booking->user_id);

    $this->travelTo($notice->new_starts_at->addMinute());

    expect($notice->fresh()?->isOpen())->toBeFalse()
        ->and($booking->fresh()?->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->fresh()?->slot?->localStartsAt()->format('H:i'))->toBe('07:00');
});
