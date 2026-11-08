<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A booking was marked attended (milestone 10e: referral rewards). After commit. */
final class BookingAttended implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
