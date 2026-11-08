<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a booking (PLAN §2). Customers may cancel only their own credit
 * bookings, before the session starts; paid single and private bookings
 * change only through an admin. The credit comes back to its pack when
 * cancelled before the cutoff and the pack has not expired; after the
 * cutoff it is used up. Admins must give a reason and may override the
 * credit decision (for example for bad weather).
 */
final class CancelBooking
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly ChangeBookingStatus $status,
        private readonly CreditPacks $credits,
        private readonly CancellationCutoff $cutoff,
    ) {}

    /**
     * @return bool whether a credit was returned
     *
     * @throws BookingRejected
     */
    public function handle(Booking $booking, User $actor, ?string $reason = null, bool $asAdmin = false, ?bool $returnCredit = null): bool
    {
        $reason = trim((string) $reason);

        if ($asAdmin) {
            if (! $actor->isAdmin()) {
                throw BookingRejected::notAllowed('Only admins can cancel this booking.');
            }

            if ($reason === '') {
                throw BookingRejected::notAllowed('Give a reason for cancelling.');
            }
        } else {
            if ($booking->user_id !== $actor->id || ! $booking->usesCredit()) {
                throw BookingRejected::notAllowed('Please contact us to change this booking.');
            }

            $returnCredit = null;
        }

        return DB::transaction(function () use ($booking, $actor, $reason, $asAdmin, $returnCredit): bool {
            $this->slotLock->acquire($booking->slot_id);
            $booking->refresh();

            if ($booking->status !== BookingStatus::Confirmed) {
                throw BookingRejected::notAllowed('This booking is not active.');
            }

            if (! $asAdmin && ! $booking->slot->starts_at->isAfter(CarbonImmutable::now())) {
                throw BookingRejected::notAllowed('This session has already started.');
            }

            $returned = false;

            if ($booking->credit_pack_id !== null) {
                $pack = CreditPack::query()->whereKey($booking->credit_pack_id)->lockForUpdate()->firstOrFail();

                $byRule = $this->cutoff->isBefore($booking->slot)
                    && $pack->expires_at->isFuture()
                    && $pack->status !== CreditPackStatus::Revoked;

                if ($returnCredit ?? $byRule) {
                    $this->credits->giveBack($pack, $booking, $actor, $asAdmin ? $reason : 'Cancelled before the cutoff');
                    $returned = true;
                }
            }

            $this->status->handle($booking, BookingStatus::Cancelled, $actor, $reason !== '' ? $reason : 'Cancelled by the customer');

            return $returned;
        });
    }
}
