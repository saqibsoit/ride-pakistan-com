<?php

declare(strict_types=1);

namespace App\Actions\Waitlist;

use App\Models\WaitlistEntry;

/**
 * waitlist:process (every minute, milestone 10d): runs OfferWaitlistSeats
 * for every slot with someone waiting or holding an offer. However seats
 * were freed (a cancellation, a move, an expired or rejected order, more
 * capacity, a reopened slot), the next person gets their offer within a
 * minute, and lapsed offers move on.
 */
final class ProcessWaitlists
{
    public function __construct(private readonly OfferWaitlistSeats $offers) {}

    /** @return int offers made */
    public function handle(): int
    {
        $made = 0;

        $slotIds = WaitlistEntry::query()->active()->distinct()->orderBy('slot_id')->pluck('slot_id');

        foreach ($slotIds as $slotId) {
            $made += $this->offers->handle((int) $slotId);
        }

        return $made;
    }
}
