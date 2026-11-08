<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Actions\Progress\RecordRiderProgress;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\RiderProgressRequest;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Run sheet (milestone 10d): save the skills a rider showed in this
 * session and the short note their customer sees. Same people as the rest
 * of the run sheet (BookingPolicy::recordProgress).
 */
final class RiderProgressController extends Controller
{
    public function __invoke(RiderProgressRequest $request, Slot $slot, Booking $booking, RecordRiderProgress $record): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $note = $request->validated('progress_note');

        $record->handle($booking, $request->skillIds(), is_string($note) ? $note : null, $user);

        return redirect()
            ->to(route('team.sessions.show', $slot).'#rider-'.$booking->id)
            ->with('runsheet', ['booking' => $booking->id, 'variant' => 'success', 'message' => 'Progress saved for '.$booking->rider->name]);
    }
}
