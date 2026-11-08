<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\BookingMoved;
use App\Support\Notifier;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves a booking to another slot (PLAN §2). Customers may move only their
 * own credit bookings, before the cutoff, to a slot their pack still
 * covers. Admins can move any booking at any time with a reason. A
 * private session moves as a group (every booking of its order in that
 * slot) and needs an empty target. Both slots are locked, in id order.
 */
final class RescheduleBooking
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly CancellationCutoff $cutoff,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return Collection<int, Booking> the bookings that moved
     *
     * @throws BookingRejected
     */
    public function handle(Booking $booking, int $targetSlotId, User $actor, ?string $reason = null, bool $asAdmin = false): Collection
    {
        $reason = trim((string) $reason);

        if ($asAdmin) {
            if (! $actor->isAdmin()) {
                throw BookingRejected::notAllowed('Only admins can move this booking.');
            }

            if ($reason === '') {
                throw BookingRejected::notAllowed('Give a reason for moving the booking.');
            }
        } elseif ($booking->user_id !== $actor->id || ! $booking->usesCredit()) {
            throw BookingRejected::notAllowed('Please contact us to change this booking.');
        }

        if ($targetSlotId === $booking->slot_id) {
            throw BookingRejected::notAllowed('Choose a different slot.');
        }

        $fromSlot = null;

        $moved = DB::transaction(function () use ($booking, $targetSlotId, $actor, $reason, $asAdmin, &$fromSlot): Collection {
            $booking->refresh();

            $moving = $booking->is_private && $booking->order_id !== null
                ? Booking::query()
                    ->where('order_id', $booking->order_id)
                    ->where('slot_id', $booking->slot_id)
                    ->where('status', BookingStatus::Confirmed)
                    ->get()
                : new Collection([$booking]);

            $ids = [$booking->slot_id, $targetSlotId];
            sort($ids);
            $locked = [];

            foreach ($ids as $id) {
                $locked[$id] = $this->slotLock->acquire($id, ignoreBookingIds: array_values(array_map('intval', $moving->modelKeys())));
            }

            $from = $locked[$booking->slot_id];
            $to = $locked[$targetSlotId];

            // Re-read under lock: another change may have landed since we looked.
            $current = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($current->slot_id !== $from->slot->id) {
                throw BookingRejected::notAllowed('This booking just changed. Please try again.');
            }

            $booking->setRawAttributes($current->getAttributes(), true);

            if ($booking->status !== BookingStatus::Confirmed) {
                throw BookingRejected::notAllowed('This booking is not active.');
            }

            if (! $asAdmin && ! $this->cutoff->isBefore($from->slot)) {
                throw BookingRejected::pastCutoff($this->cutoff->hours());
            }

            $to->assertBookable($asAdmin ? 0 : ($this->settings->int(SettingKey::MinNoticeHours) ?? 0));
            $to->assertRoomFor(array_values($moving->map(fn (Booking $b): int => $b->rider_id)->all()), $booking->is_private);

            foreach ($moving as $item) {
                if ($item->credit_pack_id !== null) {
                    $pack = CreditPack::query()->whereKey($item->credit_pack_id)->firstOrFail();

                    if (! $pack->covers($to->slot) && ! $asAdmin) {
                        throw BookingRejected::noCredits('Your package expires before that slot. Choose an earlier one.');
                    }
                }
            }

            foreach ($moving as $item) {
                $item->slot_id = $targetSlotId;
                $item->calendar_sequence++; // the updated .ics replaces the old entry
                $item->save();
            }

            $fromSlot = $from->slot;

            activity('bookings')
                ->performedOn($booking)
                ->causedBy($actor)
                ->event('rescheduled')
                ->withProperties([
                    'from_slot_id' => $from->slot->id,
                    'to_slot_id' => $targetSlotId,
                    'booking_ids' => $moving->modelKeys(),
                    'reason' => $reason !== '' ? $reason : null,
                ])
                ->log(sprintf('Moved %d booking(s) from %s to %s', $moving->count(), $from->slot->label(), $to->slot->label()));

            return $moving;
        });

        if ($fromSlot instanceof Slot) {
            // The loaded slot relation still points at the old slot.
            $moved->each(fn (Booking $b): Booking => $b->unsetRelation('slot'));
            $booking->unsetRelation('slot');

            $notice = new BookingMoved($moved, $fromSlot);
            $this->notifier->send($booking->user, $notice, $notice->dedupeKey());
        }

        return $moved;
    }
}
