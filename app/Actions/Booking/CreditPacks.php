<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\CreditPackStatus;
use App\Enums\CreditReason;
use App\Models\Booking;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\User;

/**
 * Spending and returning one credit, with its ledger entry. Callers hold
 * the pack row lock (lockForUpdate) inside their transaction.
 */
final class CreditPacks
{
    public function spend(CreditPack $pack, Booking $booking, ?User $actor): void
    {
        $pack->credits_used++;

        if ($pack->creditsRemaining() === 0) {
            $pack->status = CreditPackStatus::Exhausted;
        }

        $pack->save();

        CreditLedgerEntry::query()->create([
            'credit_pack_id' => $pack->id,
            'booking_id' => $booking->id,
            'delta' => -1,
            'reason' => CreditReason::Used,
            'actor_id' => $actor?->id,
        ]);
    }

    public function giveBack(CreditPack $pack, Booking $booking, ?User $actor, ?string $note = null): void
    {
        $pack->credits_used = max(0, $pack->credits_used - 1);

        if ($pack->status === CreditPackStatus::Exhausted) {
            $pack->status = CreditPackStatus::Active;
        }

        $pack->save();

        CreditLedgerEntry::query()->create([
            'credit_pack_id' => $pack->id,
            'booking_id' => $booking->id,
            'delta' => 1,
            'reason' => CreditReason::Returned,
            'note' => $note,
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * Takes unused credits off a pack (refunds, PLAN §3.3), with an
     * "adjusted" ledger entry. Revoking also closes the pack for good.
     *
     * @return int credits actually removed (never more than are left)
     */
    public function remove(CreditPack $pack, int $credits, ?User $actor, string $note, bool $revoke = false): int
    {
        $removed = max(0, min($credits, $pack->creditsRemaining()));

        if ($removed === 0 && ! $revoke) {
            return 0;
        }

        $pack->credits_total -= $removed;

        if ($revoke) {
            $pack->status = CreditPackStatus::Revoked;
        } elseif ($pack->creditsRemaining() === 0 && $pack->status === CreditPackStatus::Active) {
            $pack->status = CreditPackStatus::Exhausted;
        }

        $pack->save();

        CreditLedgerEntry::query()->create([
            'credit_pack_id' => $pack->id,
            'delta' => -$removed,
            'reason' => CreditReason::Adjusted,
            'note' => $note,
            'actor_id' => $actor?->id,
        ]);

        return $removed;
    }
}
