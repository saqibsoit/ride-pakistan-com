<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Booking\RespondToSlotChange;
use App\Enums\SettingKey;
use App\Enums\SlotChangeResponse;
use App\Exceptions\BookingRejected;
use App\Http\Requests\MoveAfterSlotChangeRequest;
use App\Models\Booking;
use App\Models\Slot;
use App\Models\SlotChangeNotice;
use App\Support\Settings;
use App\Support\SlotChangeLink;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * /schedule-change/{notice} (prompt 15A): the customer's choice after the
 * bulk schedule editor moved their session: keep the new time, move, or
 * cancel. The signed link from the email is the permission (no sign-in).
 * A correct signature past its expiry (the new start) or a notice already
 * answered shows a clear page instead of an error.
 */
final class SlotChangeController extends Controller
{
    /** How far ahead the move page lists sessions. */
    public const MOVE_WEEKS = 8;

    public function show(Request $request, SlotChangeNotice $notice): View|Response
    {
        if ($closed = $this->closed($request, $notice)) {
            return $closed;
        }

        return view('schedule-change.show', [
            'notice' => $notice,
            'bookings' => $notice->bookings(),
            'acceptUrl' => SlotChangeLink::accept($notice),
            'moveUrl' => SlotChangeLink::move($notice),
            'cancelUrl' => SlotChangeLink::cancel($notice),
        ]);
    }

    public function accept(Request $request, SlotChangeNotice $notice, RespondToSlotChange $respond): View|Response|RedirectResponse
    {
        return $this->answer($request, $notice, fn () => $respond->handle($notice, SlotChangeResponse::Accepted));
    }

    public function cancel(Request $request, SlotChangeNotice $notice, RespondToSlotChange $respond): View|Response|RedirectResponse
    {
        return $this->answer($request, $notice, fn () => $respond->handle($notice, SlotChangeResponse::Cancelled));
    }

    public function moveForm(Request $request, SlotChangeNotice $notice, Settings $settings): View|Response
    {
        if ($closed = $this->closed($request, $notice)) {
            return $closed;
        }

        $bookings = $notice->bookings();

        return view('schedule-change.move', [
            'notice' => $notice,
            'bookings' => $bookings,
            'slots' => $this->options($notice, $bookings, $settings),
            'action' => SlotChangeLink::move($notice),
            'backUrl' => SlotChangeLink::show($notice),
        ]);
    }

    public function move(MoveAfterSlotChangeRequest $request, SlotChangeNotice $notice, RespondToSlotChange $respond): View|Response|RedirectResponse
    {
        return $this->answer($request, $notice, fn () => $respond->handle($notice, SlotChangeResponse::Moved, $request->integer('slot_id')), 'slot_id');
    }

    /**
     * @param  callable(): SlotChangeResponse  $do
     */
    private function answer(Request $request, SlotChangeNotice $notice, callable $do, string $errorField = 'choice'): View|Response|RedirectResponse
    {
        if ($closed = $this->closed($request, $notice)) {
            return $closed;
        }

        try {
            $do();
        } catch (BookingRejected $e) {
            return back()->withErrors([$errorField => $e->getMessage()]);
        }

        $notice->refresh();

        return view('schedule-change.done', ['notice' => $notice, 'title' => 'Thanks, that’s done']);
    }

    /**
     * A tampered link is refused (403). An expired link or an answered
     * notice gets the "done" page with what happened.
     */
    private function closed(Request $request, SlotChangeNotice $notice): View|Response|null
    {
        abort_unless(URL::hasCorrectSignature($request), 403);

        if ($notice->status !== SlotChangeResponse::Pending) {
            return response()->view('schedule-change.done', ['notice' => $notice, 'title' => 'You’ve already answered']);
        }

        if (! URL::signatureHasNotExpired($request) || ! $notice->isOpen()) {
            return response()->view('schedule-change.done', ['notice' => $notice, 'title' => 'This link has expired'], 410);
        }

        return null;
    }

    /**
     * Sessions the bookings could move to: open, bookable, with room for
     * all of them (an empty slot for a private session; no private-only
     * slot for a group booking), covered by any package used.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return list<Slot>
     */
    private function options(SlotChangeNotice $notice, Collection $bookings, Settings $settings): array
    {
        if ($bookings->isEmpty()) {
            return [];
        }

        $private = $bookings->contains('is_private', true);
        $riders = $bookings->count();
        $packs = $bookings->map(fn (Booking $b) => $b->creditPack)->filter();

        $slots = Slot::query()
            ->withSeatCounts()
            ->bookable($settings->int(SettingKey::MinNoticeHours) ?? 0)
            ->whereKeyNot($notice->slot_id)
            ->where('slots.starts_at', '<', now()->addWeeks(self::MOVE_WEEKS))
            ->orderBy('slots.starts_at')
            ->get()
            ->filter(function (Slot $slot) use ($private, $riders, $packs): bool {
                if ($private ? (! $slot->isEmpty() || $slot->capacity < $riders) : ($slot->private_only || $slot->seatsFree() < $riders)) {
                    return false;
                }

                return $packs->every(fn ($pack): bool => $pack->covers($slot));
            })
            ->values()
            ->all();

        return array_values($slots);
    }
}
