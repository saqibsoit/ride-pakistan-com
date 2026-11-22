<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which regular lesson slots an event occurrence closes (decided
 * 2026-11-22). Overlap counts the slot buffer (booking.slot_buffer_minutes);
 * day closes every lesson on the occurrence's Pakistan dates. Closing never
 * cancels a booking: clashes are listed for an admin (ApplyLessonClosure).
 */
enum LessonClosure: string implements HasLabel
{
    case None = 'none';
    case Overlap = 'overlap';
    case Day = 'day';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Keep lesson slots open',
            self::Overlap => 'Close lessons that overlap (with the slot buffer)',
            self::Day => 'Close every lesson on the day(s)',
        };
    }
}
