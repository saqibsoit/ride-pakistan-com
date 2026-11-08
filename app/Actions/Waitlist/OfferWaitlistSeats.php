<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Actions\Booking\SlotLock;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Enums\WaitlistStatus;
use App\Models\BookingHold;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistOffer;
use App\Support\Notifier;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Hands freed seats in a slot to its waitlist (milestone 10d). Under the
 * slot row lock, like every other seat write:
 *
 * 1. Offers past their expiry end ("expired"; their held seats go back).
 * 2. A slot that can't be booked any more (cancelled, closed, private-only
 *    or inside the booking cutoff) closes its remaining entries.
 * 3. Otherwise each waiting entry in turn, oldest first, whose rider count
 *    fits the free seats gets an offer: the seats are held for it (an
 *    ordinary booking hold, so nobody else can take them and no seat is
 *    ever held twice) for booking.waitlist_offer_hours [2], never past
 *    the booking cutoff. Larger requests further up keep their place.
 *
 * Emails go out after commit. Run every minute (waitlist:process) and
 * straight after a cancellation, a move or someone leaving the waitlist.
 */
final class OfferWaitlistSeats
{
    /** An offer shorter than this isn't worth sending. */
    public const MIN_OFFER_MINUTES = 15;

    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly ChangeWaitlistStatus $status,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
    ) {}

    /** @return int offers made */
    public function handle(int $slotId, ?User $actor = null, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        if (! WaitlistEntry::query()->where('slot_id', $slotId)->active()->exists()) {
            return 0;
        }

        /** @var list<WaitlistEntry> $offered */
        $offered = DB::transaction(function () use ($slotId, $actor, $now): array {
            $locked = $this->slotLock->acquire($slotId);
            $slot = $locked->slot;

            $entries = WaitlistEntry::query()
                ->where('slot_id', $slotId)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($entries as $entry) {
                if ($entry->status === WaitlistStatus::Offered && $entry->offer_expires_at !== null && ! $entry->offer_expires_at->isAfter($now)) {
                    $this->status->handle($entry, WaitlistStatus::Expired, $actor, 'Offer not taken in time');
                }
            }

            $minNotice = $this->settings->int(SettingKey::MinNoticeHours) ?? 0;
            $cutoff = $slot->starts_at->subHours(max(0, $minNotice));

            if ($slot->status === SlotStatus::Cancelled || $slot->private_only || ! $cutoff->isAfter($now)) {
                foreach ($entries->filter(fn (WaitlistEntry $e): bool => $e->status->isActive()) as $entry) {
                    $this->status->handle($entry, WaitlistStatus::Closed, $actor, match (true) {
                        $slot->status === SlotStatus::Cancelled => 'The session was cancelled',
                        $slot->private_only => 'The session is now private only',
                        default => 'Booking has closed for this session',
                    });
                }

                return [];
            }

            // A closed slot may reopen: entries keep their place, but no offers meanwhile.
            if ($slot->status !== SlotStatus::Open) {
                return [];
            }

            $hours = max(1, $this->settings->int(SettingKey::WaitlistOfferHours) ?? 2);
            $expiresAt = $now->addHours($hours)->min($cutoff);

            if ($expiresAt->lessThan($now->addMinutes(self::MIN_OFFER_MINUTES))) {
                return [];
            }

            // An offer's hold expires with the offer, so the lock's count already leaves expired offers out.
            $free = $locked->free();
            $made = [];

            foreach ($entries->filter(fn (WaitlistEntry $e): bool => $e->status === WaitlistStatus::Waiting) as $entry) {
                if ($free <= 0) {
                    break;
                }

                if ($entry->seats > $free) {
                    continue; // first matching person: a bigger group keeps its place
                }

                BookingHold::query()->create([
                    'slot_id' => $slotId,
                    'order_id' => null,
                    'waitlist_entry_id' => $entry->id,
                    'seats' => $entry->seats,
                    'is_private' => false,
                    'expires_at' => $expiresAt,
                ]);

                $this->status->handle($entry, WaitlistStatus::Offered, $actor, offerExpiresAt: $expiresAt);
                $free -= $entry->seats;
                $made[] = $entry;
            }

            return $made;
        });

        foreach ($offered as $entry) {
            $this->notifier->send($entry->user, new WaitlistOffer($entry), Notifier::key(WaitlistOffer::type(), $entry, $entry->user));
        }

        return count($offered);
    }
}
