<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\WaitlistStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\BookingHold;
use App\Models\User;
use App\Models\WaitlistEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only way a waitlist entry's status changes (milestone 10d).
 * Validates the move against WaitlistStatus, releases the offer's held
 * seats when an offer ends without a booking, and writes the audit log
 * ("waitlist"). Callers that hand seats around (offers, bookings) hold the
 * slot lock already; this locks the entry row too.
 */
final class ChangeWaitlistStatus
{
    /**
     * @throws InvalidStatusTransition
     */
    public function handle(WaitlistEntry $entry, WaitlistStatus $to, ?User $actor = null, ?string $reason = null, ?CarbonImmutable $offerExpiresAt = null): WaitlistEntry
    {
        $reason = OrderStateMachine::cleanReason($reason);

        if ($to === WaitlistStatus::Offered && $offerExpiresAt === null) {
            throw InvalidStatusTransition::reasonRequired('Waitlist entry #'.$entry->id, $to->value);
        }

        return DB::transaction(function () use ($entry, $to, $actor, $reason, $offerExpiresAt): WaitlistEntry {
            $locked = WaitlistEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw InvalidStatusTransition::between('Waitlist entry #'.$locked->id, $from->value, $to->value);
            }

            $now = CarbonImmutable::now();
            $locked->status = $to;

            if ($to === WaitlistStatus::Offered) {
                $locked->offered_at = $now;
                $locked->offer_expires_at = $offerExpiresAt;
            } elseif ($to === WaitlistStatus::Booked) {
                $locked->booked_at = $now;
            } elseif ($to === WaitlistStatus::Cancelled || $to === WaitlistStatus::Closed) {
                $locked->closed_reason = $reason !== null ? mb_substr($reason, 0, 255) : null;
            }

            $locked->save();

            // Seats held for an offer go back as soon as the offer ends.
            if ($to !== WaitlistStatus::Offered) {
                BookingHold::query()->where('waitlist_entry_id', $locked->id)->delete();
            }

            activity('waitlist')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('status_changed')
                ->withProperties(array_filter([
                    'from' => $from->value,
                    'to' => $to->value,
                    'slot_id' => $locked->slot_id,
                    'seats' => $locked->seats,
                    'reason' => $reason,
                    'offer_expires_at' => $offerExpiresAt?->toIso8601String(),
                ], fn (mixed $value): bool => $value !== null))
                ->log(sprintf('Waitlist #%d: %s → %s', $locked->id, $from->label(), $to->label()));

            $entry->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }
}
