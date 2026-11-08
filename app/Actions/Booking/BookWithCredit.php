<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\BookingConfirmed;
use App\Support\Notifier;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Books group sessions with package credits, one credit per rider
 * (PLAN §2). Checked under the slot lock and the pack row locks: the
 * pack must be active, unexpired, with enough credits, and expire after
 * the session starts; the slot must have a seat per rider; a rider is
 * never booked twice in a slot. Credits never pay for private sessions.
 * Uses the packs that expire soonest first.
 */
final class BookWithCredit
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly GenerateTicketCode $tickets,
        private readonly CreditPacks $credits,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  list<int>  $riderIds
     * @return Collection<int, Booking>
     *
     * @throws BookingRejected
     */
    public function handle(User $user, int $slotId, array $riderIds, ?User $actor = null, ?CreditPack $only = null): Collection
    {
        $riderIds = array_values(array_unique(array_map('intval', $riderIds)));

        if ($riderIds === [] || $user->riders()->whereIn('id', $riderIds)->count() !== count($riderIds)) {
            throw BookingRejected::invalidRiders();
        }

        if ($only !== null && $only->user_id !== $user->id) {
            throw BookingRejected::noCredits();
        }

        $bookings = DB::transaction(function () use ($user, $slotId, $riderIds, $actor, $only): Collection {
            $slot = $this->slotLock->acquire($slotId);

            // Admins may book inside the notice period; customers may not.
            $slot->assertBookable($actor !== null && $actor->isAdmin() ? 0 : ($this->settings->int(SettingKey::MinNoticeHours) ?? 0));
            $slot->assertRoomFor($riderIds, private: false);

            $packs = $this->lockedPacks($user, $slot->slot, $only);
            $available = $packs->sum(fn (CreditPack $pack): int => $pack->creditsRemaining());

            if ($available < count($riderIds)) {
                throw BookingRejected::noCredits($available === 0
                    ? 'You have no credits that are valid for that date.'
                    : 'You have '.$available.' '.($available === 1 ? 'credit' : 'credits').' valid for that date.');
            }

            $bookings = new Collection;

            foreach ($riderIds as $riderId) {
                $pack = $packs->first(fn (CreditPack $p): bool => $p->creditsRemaining() > 0)
                    ?? throw BookingRejected::noCredits();

                $booking = Booking::query()->create([
                    'slot_id' => $slotId,
                    'order_id' => null,
                    'rider_id' => $riderId,
                    'user_id' => $user->id,
                    'credit_pack_id' => $pack->id,
                    'ticket_code' => $this->tickets->handle(),
                ]);

                $this->credits->spend($pack, $booking, $actor ?? $user);
                $bookings->push($booking);
            }

            activity('bookings')
                ->performedOn($slot->slot)
                ->causedBy($actor ?? $user)
                ->event('booked_with_credit')
                ->withProperties(['user_id' => $user->id, 'booking_ids' => $bookings->modelKeys()])
                ->log(count($riderIds).' booked with credits for '.$slot->slot->label());

            return $bookings;
        });

        // Tickets, map pin and calendar file (milestone 10d); queued after commit.
        $this->notifier->send($user, new BookingConfirmed($bookings), Notifier::key(BookingConfirmed::type(), $bookings->first(), $user));

        return $bookings;
    }

    /**
     * @return Collection<int, CreditPack>
     */
    private function lockedPacks(User $user, Slot $slot, ?CreditPack $only): Collection
    {
        return CreditPack::query()
            ->where('user_id', $user->id)
            ->when($only !== null, fn ($q) => $q->whereKey($only?->id))
            ->usableFor($slot)
            ->lockForUpdate()
            ->get();
    }
}
