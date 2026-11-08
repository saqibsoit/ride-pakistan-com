<?php

declare(strict_types=1);

namespace App\Actions\Slots;

use App\Models\Slot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** One slot in a SchedulePreview, with Pakistan-time labels. */
final class SchedulePreviewItem
{
    public function __construct(
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $capacity,
        public readonly ?string $note = null,
        public readonly int $seatsBooked = 0,
    ) {}

    public static function make(CarbonInterface $startsAt, CarbonInterface $endsAt, int $capacity, ?string $note = null, int $seatsBooked = 0): self
    {
        return new self(CarbonImmutable::instance($startsAt), CarbonImmutable::instance($endsAt), $capacity, $note, $seatsBooked);
    }

    /** "Sat 14 Nov 2026, 08:00–10:00" in Pakistan time. */
    public function label(): string
    {
        $tz = Slot::displayTimezone();

        return $this->startsAt->setTimezone($tz)->format('D j M Y, H:i').'–'.$this->endsAt->setTimezone($tz)->format('H:i');
    }
}
