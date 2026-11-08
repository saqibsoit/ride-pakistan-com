<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\CreditPackStatus;
use App\Enums\CreditReason;
use App\Exceptions\BookingRejected;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Admin extends a pack's expiry (PLAN §2: "admin can extend manually with
 * a logged reason"). An expired pack with credits left becomes usable
 * again. Recorded as an "adjusted" ledger entry (no credit change) and in
 * the audit log.
 */
final class ExtendCreditPack
{
    /**
     * @throws BookingRejected
     */
    public function handle(CreditPack $pack, CarbonImmutable $newExpiry, User $admin, string $reason): CreditPack
    {
        $reason = trim($reason);

        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can extend credits.');
        }

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for the extension.');
        }

        return DB::transaction(function () use ($pack, $newExpiry, $admin, $reason): CreditPack {
            $locked = CreditPack::query()->whereKey($pack->id)->lockForUpdate()->firstOrFail();
            $old = $locked->expires_at;

            if ($locked->status === CreditPackStatus::Revoked) {
                throw BookingRejected::notAllowed('A revoked pack cannot be extended.');
            }

            if (! $newExpiry->isAfter($old) || ! $newExpiry->isFuture()) {
                throw BookingRejected::notAllowed('The new expiry must be later than the current one and in the future.');
            }

            $locked->expires_at = $newExpiry;

            if ($locked->status === CreditPackStatus::Expired) {
                $locked->status = $locked->creditsRemaining() > 0 ? CreditPackStatus::Active : CreditPackStatus::Exhausted;
            }

            $locked->save();

            CreditLedgerEntry::query()->create([
                'credit_pack_id' => $locked->id,
                'delta' => 0,
                'reason' => CreditReason::Adjusted,
                'note' => 'Extended to '.$newExpiry->toDateString().': '.$reason,
                'actor_id' => $admin->id,
            ]);

            activity('credits')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('extended')
                ->withProperties(['from' => $old->toIso8601String(), 'to' => $newExpiry->toIso8601String(), 'reason' => $reason])
                ->log('Credit pack extended');

            $pack->setRawAttributes($locked->getAttributes(), true);

            return $pack;
        });
    }
}
