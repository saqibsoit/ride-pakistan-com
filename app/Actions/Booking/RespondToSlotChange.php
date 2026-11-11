<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Payments\FlagOrderForReview;
use App\Enums\SlotChangeResponse;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Order;
use App\Models\SlotChangeNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The customer's answer to a slot time change (prompt 15A), from the
 * signed email links. The only place a SlotChangeNotice changes status:
 * one answer, while the session hasn't started at its new time.
 *
 * - Keep: nothing else changes.
 * - Move: every booking of theirs in the slot moves together to a slot
 *   with room (RescheduleBooking, slot-change mode: any kind, no cutoff).
 * - Cancel: credit and no-charge bookings are cancelled, the credit back
 *   whatever the cutoff (CancelBooking, slot-change mode). Directly paid
 *   bookings are kept and their order is flagged for the admin to refund
 *   (decided 2026-11-11): nothing is cancelled until an admin acts.
 *
 * Audit log `bookings`, event `slot_change_answered`.
 */
final class RespondToSlotChange
{
    public function __construct(
        private readonly RescheduleBooking $reschedule,
        private readonly CancelBooking $cancel,
        private readonly FlagOrderForReview $flag,
    ) {}

    /**
     * @throws BookingRejected
     */
    public function handle(SlotChangeNotice $notice, SlotChangeResponse $answer, ?int $targetSlotId = null): SlotChangeResponse
    {
        if ($answer === SlotChangeResponse::Pending) {
            throw BookingRejected::notAllowed('Choose what you’d like to do.');
        }

        return DB::transaction(function () use ($notice, $answer, $targetSlotId): SlotChangeResponse {
            $current = SlotChangeNotice::query()->whereKey($notice->id)->lockForUpdate()->firstOrFail();
            $notice->setRawAttributes($current->getAttributes(), true);

            if ($current->status !== SlotChangeResponse::Pending) {
                throw BookingRejected::notAllowed('You’ve already answered: '.mb_strtolower($current->status->label()).'.');
            }

            if (! $current->isOpen()) {
                throw BookingRejected::notAllowed('This session has already started, so it can’t be changed here.');
            }

            $user = $current->user;
            $bookings = $current->bookings();

            if ($bookings->isEmpty() && $answer !== SlotChangeResponse::Accepted) {
                throw BookingRejected::notAllowed('You have no active bookings in this session any more.');
            }

            $final = $answer;
            $detail = [];

            if ($answer === SlotChangeResponse::Moved) {
                if ($targetSlotId === null) {
                    throw BookingRejected::notAllowed('Choose a session to move to.');
                }

                /** @var Booking $first */
                $first = $bookings->first();
                $moved = $this->reschedule->handle($first, $targetSlotId, $user, forSlotChange: true);
                $detail = ['to_slot_id' => $targetSlotId, 'booking_ids' => $moved->modelKeys()];
            }

            if ($answer === SlotChangeResponse::Cancelled || $answer === SlotChangeResponse::RefundRequested) {
                $final = SlotChangeResponse::Cancelled;
                $flagged = [];
                $cancelled = [];

                foreach ($bookings as $booking) {
                    if ($booking->credit_pack_id === null && $booking->order_id !== null) {
                        if (! isset($flagged[$booking->order_id])) {
                            $order = Order::query()->whereKey($booking->order_id)->lockForUpdate()->firstOrFail();
                            $this->flag->handle($order, 'slot_change_refund_requested', sprintf(
                                'The customer asked for a refund after the session moved from %s to %s. The booking is still active: refund it, or cancel or move it.',
                                $current->oldLabel(),
                                $current->newLabel(),
                            ), ['slot_id' => $current->slot_id, 'notice_id' => $current->id]);
                            $flagged[$booking->order_id] = true;
                        }

                        $final = SlotChangeResponse::RefundRequested;

                        continue;
                    }

                    $this->cancel->handle($booking, $user, 'Cancelled after the session time changed', forSlotChange: true);
                    $cancelled[] = $booking->id;
                }

                $detail = ['cancelled_booking_ids' => $cancelled, 'flagged_order_ids' => array_keys($flagged)];
            }

            if (! $current->status->canTransitionTo($final)) {
                throw BookingRejected::notAllowed('That isn’t possible any more.');
            }

            $from = $current->status;
            $current->status = $final;
            $current->responded_at = CarbonImmutable::now();
            $current->save();
            $notice->setRawAttributes($current->getAttributes(), true);

            activity('bookings')
                ->performedOn($current)
                ->causedBy($user)
                ->event('slot_change_answered')
                ->withProperties([...$detail, 'from' => $from->value, 'to' => $final->value, 'slot_id' => $current->slot_id])
                ->log(sprintf('Slot time change (%s → %s): %s', $current->oldLabel(), $current->newLabel(), $final->label()));

            return $final;
        });
    }
}
