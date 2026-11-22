<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;

/**
 * Derived event state (decided 2026-11-22): the dates to come with their
 * seats, and whether the event is sold out, has few places left or is
 * past. Never stored. Customers never see seat counts: only "Fully
 * booked" and "Few spots left" (booking.selling_fast_threshold).
 */
final class EventAvailability
{
    /**
     * @param  Collection<int, Slot>  $dates  upcoming, not cancelled, with seat counts
     */
    public function __construct(
        public readonly RideEvent $event,
        public readonly Collection $dates,
        public readonly int $threshold,
    ) {}

    public static function for(RideEvent $event): self
    {
        return new self($event, $event->upcomingOccurrences(), app(Settings::class)->int(SettingKey::SellingFastThreshold) ?? 2);
    }

    /**
     * Dates still taking bookings (open, inside the booking window).
     *
     * @return Collection<int, Slot>
     */
    public function bookable(): Collection
    {
        return $this->dates->filter(fn (Slot $slot): bool => $slot->status === SlotStatus::Open && $this->event->isBookingOpen($slot))->values();
    }

    public function isPast(): bool
    {
        return $this->dates->isEmpty();
    }

    public function isSoldOut(): bool
    {
        return ! $this->isPast() && $this->bookable()->every(fn (Slot $slot): bool => $slot->seatsFree() === 0);
    }

    public function isSellingFast(): bool
    {
        $free = $this->bookable()->sum(fn (Slot $slot): int => $slot->seatsFree());

        return $free > 0 && $free <= $this->threshold;
    }

    /** "Fully booked", "Few spots left" or null, for cards. */
    public function badge(): ?string
    {
        return match (true) {
            $this->isPast() => null,
            $this->isSoldOut() => 'Fully booked',
            $this->isSellingFast() => 'Few spots left',
            default => null,
        };
    }

    /** "2 dates · 15 places left" / "Sold out" / "Past" for the admin list. */
    public function adminLabel(): string
    {
        if ($this->isPast()) {
            return 'Past (no dates to come)';
        }

        if ($this->isSoldOut()) {
            return 'Sold out';
        }

        $free = $this->bookable()->sum(fn (Slot $slot): int => $slot->seatsFree());

        return $this->dates->count().' '.($this->dates->count() === 1 ? 'date' : 'dates').' · '.$free.' places left';
    }
}
