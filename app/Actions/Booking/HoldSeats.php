<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Waitlist\ClaimWaitlistPlace;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Models\BookingHold;
use App\Models\Order;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Holds seats in a slot for an unpaid order (PLAN §3.1, §5). Runs under
 * the slot row lock: confirmed bookings + unexpired holds never exceed
 * capacity. A private session needs an empty slot and holds all of it.
 */
final class HoldSeats
{
    public function __construct(
        private readonly SlotLock $lock,
        private readonly Settings $settings,
        private readonly ClaimWaitlistPlace $waitlist,
    ) {}

    /**
     * @param  list<int>  $riderIds  one seat each (private: the whole slot is held)
     *
     * @throws BookingRejected
     */
    public function handle(Order $order, int $slotId, array $riderIds, bool $private, CarbonImmutable $expiresAt): BookingHold
    {
        if ($riderIds === []) {
            throw BookingRejected::invalidRiders();
        }

        return DB::transaction(function () use ($order, $slotId, $riderIds, $private, $expiresAt): BookingHold {
            // The customer's own waitlist offer counts as free seats for them
            // (milestone 10d). Not for a guest order: whether the account
            // behind the email has an offer must not change what a guest
            // sees, and a stranger mustn't use up someone's offer.
            $guest = $order->placed_as_guest;
            $slot = $this->lock->acquire($slotId, claimFor: $guest ? null : $order->user_id);

            $slot->assertBookable($this->settings->int(SettingKey::MinNoticeHours) ?? 0);
            $slot->assertRoomFor($riderIds, $private);

            $hold = BookingHold::query()->create([
                'slot_id' => $slotId,
                'order_id' => $order->id,
                'seats' => $private ? $slot->slot->capacity : count($riderIds),
                'is_private' => $private,
                'expires_at' => $expiresAt,
            ]);

            if (! $guest) {
                $this->waitlist->handle($slot, $order->user);
            }

            return $hold;
        });
    }
}
