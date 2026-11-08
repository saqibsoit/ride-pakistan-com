<?php

declare(strict_types=1);

namespace App\Http\Controllers\Team;

use App\Actions\Booking\CheckInBooking;
use App\Actions\Booking\WriteSessionNote;
use App\Actions\Horses\AssignHorse;
use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\HorseAssignmentRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Team\AssignHorseRequest;
use App\Http\Requests\Team\CheckInRequest;
use App\Http\Requests\Team\SessionNoteRequest;
use App\Models\Booking;
use App\Models\Horse;
use App\Models\Slot;
use App\Models\User;
use App\Support\SessionTimes;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Mobile run sheet (milestone 10b): one page per session with every rider,
 * their level, notes, emergency contact, horse, payment status (never
 * amounts), check-in and the trainer's session note. Staff and admins see
 * every session; trainers only their own (SlotPolicy::viewRunSheet).
 * Writes go through CheckInBooking, AssignHorse and WriteSessionNote.
 */
final class RunSheetController extends Controller
{
    public const DAYS_AHEAD = 14;

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $today = SessionTimes::now()->startOfDay();

        $slots = Slot::query()
            ->withSeatCounts()
            ->with(['trainer', 'weather'])
            ->where('status', '!=', SlotStatus::Cancelled)
            ->where('starts_at', '>=', $today->utc())
            ->where('starts_at', '<', $today->addDays(self::DAYS_AHEAD)->utc())
            ->when(! $user->isTeamMember(), fn ($query) => $query->where('trainer_id', $user->id))
            ->orderBy('starts_at')
            ->get();

        return view('team.sessions.index', [
            'slots' => $slots,
            'today' => $today,
            'isTrainer' => ! $user->isTeamMember(),
        ]);
    }

    public function show(Request $request, Slot $slot, CheckInBooking $checkIn): View
    {
        Gate::authorize('viewRunSheet', $slot);

        $bookings = $slot->bookings()
            ->where('status', '!=', BookingStatus::Cancelled)
            ->with(['rider', 'user', 'horse', 'order', 'creditPack.order'])
            ->orderBy('id')
            ->get();

        $horses = Horse::query()->working()->orderBy('sort')->orderBy('name')->get();

        return view($request->boolean('print') ? 'team.sessions.print' : 'team.sessions.show', [
            'slot' => $slot->loadMissing(['trainer', 'weather']),
            'bookings' => $bookings,
            'horses' => $horses,
            'states' => $bookings->mapWithKeys(fn (Booking $booking): array => [$booking->id => [
                'state' => $checkIn->state($booking),
                'noShow' => $checkIn->canMarkNoShow($booking),
            ]])->all(),
            'opensAt' => $bookings->isEmpty() ? null : $checkIn->opensAt($bookings->first()),
            'warnings' => $bookings
                ->mapWithKeys(fn (Booking $booking): array => [$booking->id => $booking->horse === null ? [] : AssignHorse::warnings($booking, $booking->horse)])
                ->all(),
        ]);
    }

    public function checkIn(CheckInRequest $request, Slot $slot, Booking $booking, CheckInBooking $checkIn): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $to = $request->status();

        if ($booking->status === $to) {
            return $this->back($slot, $booking, 'error', $to === BookingStatus::Attended ? 'Already checked in. Nothing changed.' : 'No-show was already recorded.');
        }

        try {
            $checkIn->handle($booking, $user, $to);
        } catch (BookingRejected $e) {
            return $this->back($slot, $booking, 'error', $e->getMessage());
        }

        return $this->back($slot, $booking, 'success', $to === BookingStatus::Attended
            ? 'Checked in: '.$booking->rider->name
            : 'No-show recorded for '.$booking->rider->name);
    }

    public function horse(AssignHorseRequest $request, Slot $slot, Booking $booking, AssignHorse $assign): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $horseId = $request->validated('horse_id');
        $horse = $horseId === null ? null : Horse::query()->findOrFail((int) $horseId);

        try {
            $result = $assign->handle($booking, $horse, $user);
        } catch (HorseAssignmentRejected $e) {
            return $this->back($slot, $booking, 'error', $e->getMessage());
        }

        $message = $horse === null ? 'Horse removed for '.$booking->rider->name : $booking->rider->name.' is on '.$horse->name;

        return $result->warnings === []
            ? $this->back($slot, $booking, 'success', $message)
            : $this->back($slot, $booking, 'warning', $message.'. '.implode(' ', $result->warnings));
    }

    public function note(SessionNoteRequest $request, Slot $slot, Booking $booking, WriteSessionNote $write): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $note = $request->validated('session_note');

        $write->handle($booking, is_string($note) ? $note : null, $user);

        return $this->back($slot, $booking, 'success', 'Note saved for '.$booking->rider->name);
    }

    /** Back to the rider's card on the run sheet with a message for it. */
    private function back(Slot $slot, Booking $booking, string $variant, string $message): RedirectResponse
    {
        return redirect()
            ->to(route('team.sessions.show', $slot).'#rider-'.$booking->id)
            ->with('runsheet', ['booking' => $booking->id, 'variant' => $variant, 'message' => $message]);
    }
}
