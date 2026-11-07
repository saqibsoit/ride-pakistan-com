<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Slot;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A slot will not run. Milestone 8 listens to notify booked riders and
 * handle their bookings and credits.
 */
final class SlotCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Slot $slot) {}
}
