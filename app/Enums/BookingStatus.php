<?php

declare(strict_types=1);

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Attended = 'attended';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::NoShow => 'No-show',
            default => ucfirst($this->value),
        };
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
