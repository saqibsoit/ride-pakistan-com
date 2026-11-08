<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Http\Controllers\Controller;
use App\Models\Rider;
use App\Models\Slot;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * A rider's sessions with horse, attendance and the trainer's notes
 * (milestone 10b). The office team for any rider; a trainer for riders who
 * have booked one of their sessions (RiderPolicy::viewHistory). No money.
 */
final class RiderHistoryController extends Controller
{
    public function show(Rider $rider): View
    {
        Gate::authorize('viewHistory', $rider);

        $bookings = $rider->bookings()
            ->join('slots', 'slots.id', '=', 'bookings.slot_id')
            ->orderByDesc('slots.starts_at')
            ->select('bookings.*')
            ->with(['slot.trainer', 'horse'])
            ->limit(100)
            ->get();

        return view('team.riders.show', [
            'rider' => $rider,
            'bookings' => $bookings,
            'now' => now()->toImmutable(),
            'tz' => Slot::displayTimezone(),
        ]);
    }
}
