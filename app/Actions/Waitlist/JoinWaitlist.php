<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Booking\SlotLock;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * A signed-in customer joins a full slot's waitlist with the number of
 * riders they want to bring (milestone 10d). Group sessions only (a
 * private session needs an empty slot, and a slot a private session holds
 * or has booked never frees up); only while the slot can still be booked; only when there aren't enough free seats to book now; one
 * active entry per customer per slot. Checked under the slot lock.
 */
final class JoinWaitlist
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly Settings $settings,
    ) {}

    /**
     * @throws BookingRejected
     */
    public function handle(User $user, int $slotId, int $seats): WaitlistEntry
    {
        if ($user->isPanelUser()) {
            throw BookingRejected::notAllowed('Team accounts can’t join a waitlist.');
        }

        return DB::transaction(function () use ($user, $slotId, $seats): WaitlistEntry {
            $locked = $this->slotLock->acquire($slotId);
            $slot = $locked->slot;

            $locked->assertBookable($this->settings->int(SettingKey::MinNoticeHours) ?? 0);

            if ($slot->private_only) {
                throw BookingRejected::privateOnly();
            }

            // A private hold or booking takes the whole slot: nothing will come free (prompt 14).
            if ($locked->privatelyTaken) {
                throw BookingRejected::bookedPrivately();
            }

            if ($seats < 1 || $seats > $slot->capacity) {
                throw BookingRejected::tooManyRiders($slot->capacity);
            }

            if ($locked->free() >= $seats) {
                throw BookingRejected::notAllowed('There are seats free in this session. Book them now.');
            }

            $existing = WaitlistEntry::query()
                ->where('slot_id', $slotId)
                ->where('user_id', $user->id)
                ->active()
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw BookingRejected::notAllowed('You’re already on the waitlist for this session.');
            }

            $entry = WaitlistEntry::query()->create([
                'slot_id' => $slotId,
                'user_id' => $user->id,
                'seats' => $seats,
            ]);

            activity('waitlist')
                ->performedOn($entry)
                ->causedBy($user)
                ->event('joined')
                ->withProperties(['slot_id' => $slotId, 'seats' => $seats])
                ->log('Joined the waitlist for '.$slot->label());

            return $entry;
        });
    }
}
