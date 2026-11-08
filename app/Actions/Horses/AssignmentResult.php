<?php

declare(strict_types=1);

namespace App\Actions\Horses;

use App\Models\Booking;

/** What AssignHorse did, with warnings that don't block (level mismatch). */
final readonly class AssignmentResult
{
    /** @param  list<string>  $warnings */
    public function __construct(
        public Booking $booking,
        public bool $changed,
        public array $warnings = [],
    ) {}
}
