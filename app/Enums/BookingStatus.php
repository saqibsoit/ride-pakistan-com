<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasLabel
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Attended = 'attended';
    case NoShow = 'no_show';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
    }

    /**
     * Allowed status changes (ChangeBookingStatus). Attended and no-show
     * can be swapped to correct a check-in mistake; cancelled is final.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Confirmed => [self::Cancelled, self::Attended, self::NoShow],
            self::Attended => [self::NoShow],
            self::NoShow => [self::Attended],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Counts against slot capacity. */
    public function occupiesSeat(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * @return list<string>
     */
    public static function seatOccupyingValues(): array
    {
        $values = [];

        foreach (self::cases() as $status) {
            if ($status->occupiesSeat()) {
                $values[] = $status->value;
            }
        }

        return $values;
    }
}
