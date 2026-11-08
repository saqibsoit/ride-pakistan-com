<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\OrderStatus;
use App\Exceptions\AccountActionBlocked;
use App\Models\CreditPack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Delete my account" (PLAN §7). Soft-deletes the account and ends every
 * session; AnonymiseAccount runs after the grace period (and retention).
 *
 * Not allowed while the customer has upcoming confirmed bookings or unpaid
 * open orders. Unused credits are forfeited only with explicit consent.
 * Team accounts are removed by an admin, not here.
 */
final class RequestAccountDeletion
{
    /**
     * @throws AccountActionBlocked
     */
    public function handle(User $user, bool $forfeitCredits = false): void
    {
        DB::transaction(function () use ($user, $forfeitCredits): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            $blockers = $this->blockers($locked);
            $credits = $this->unusedCredits($locked);

            if ($credits > 0 && ! $forfeitCredits) {
                $blockers[] = 'Confirm that you give up your '.$credits.' unused '.($credits === 1 ? 'credit' : 'credits').'.';
            }

            if ($blockers !== []) {
                throw new AccountActionBlocked($blockers);
            }

            $locked->forceFill(['session_version' => $locked->session_version + 1])->save();
            $locked->delete();

            activity('account')
                ->performedOn($locked)
                ->causedBy($locked)
                ->event('deletion-requested')
                ->withProperties(['credits_forfeited' => $credits])
                ->log('Customer asked to delete their account');

            $user->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * What stops deletion right now (empty when it can go ahead).
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        if ($user->isTeamMember()) {
            $blockers[] = 'Team accounts are removed by an admin.';
        }

        $upcoming = $user->bookings()
            ->where('status', BookingStatus::Confirmed)
            ->whereHas('slot', fn ($slot) => $slot->where('starts_at', '>', now()))
            ->count();

        if ($upcoming > 0) {
            $blockers[] = 'You have '.$upcoming.' upcoming '.($upcoming === 1 ? 'booking' : 'bookings').'. Cancel '.($upcoming === 1 ? 'it' : 'them').' or contact us first.';
        }

        $unpaid = $user->orders()
            ->whereIn('status', OrderStatus::unpaidStatuses())
            ->count();

        if ($unpaid > 0) {
            $blockers[] = 'You have '.$unpaid.' unpaid '.($unpaid === 1 ? 'order' : 'orders').' still open. Let '.($unpaid === 1 ? 'it' : 'them').' expire or contact us first.';
        }

        return $blockers;
    }

    /** Credits still usable on active, unexpired packs. */
    public function unusedCredits(User $user): int
    {
        return (int) $user->creditPacks()
            ->where('status', CreditPackStatus::Active)
            ->where('expires_at', '>', now())
            ->get()
            ->sum(fn (CreditPack $pack): int => $pack->creditsRemaining());
    }
}
