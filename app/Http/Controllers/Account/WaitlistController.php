<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Waitlist\JoinWaitlist;
use App\Actions\Waitlist\LeaveWaitlist;
use App\Enums\SettingKey;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\JoinWaitlistRequest;
use App\Models\Slot;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\WaitlistOffer;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The customer's waitlists (milestone 10d): join a full slot (the public
 * pages link here, so guests sign in first), see their place and any
 * offer, and leave. Entries are scoped to the signed-in customer; another
 * customer's entry is a 404.
 */
final class WaitlistController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $entries = WaitlistEntry::query()
            ->where('user_id', $user->id)
            ->whereHas('slot', fn ($q) => $q->where('starts_at', '>', now()))
            ->with('slot')
            ->latest('id')
            ->get();

        return view('account.waitlist', [
            'entries' => $entries,
            'bookUrl' => fn (WaitlistEntry $entry): string => WaitlistOffer::bookUrl($entry),
        ]);
    }

    public function create(Request $request, Slot $slot, Settings $settings): View
    {
        /** @var User $user */
        $user = $request->user();

        $slot = Slot::query()->withSeatCounts()->findOrFail($slot->id);

        if ($slot->status === SlotStatus::Cancelled || $slot->private_only || ! $slot->starts_at->isFuture()) {
            abort(404);
        }

        $existing = WaitlistEntry::query()->where('slot_id', $slot->id)->where('user_id', $user->id)->active()->first();

        return view('account.waitlist-join', [
            'slot' => $slot,
            'free' => $slot->seatsFree(),
            'existing' => $existing,
            'maxSeats' => $slot->capacity,
            'riders' => max(1, min($slot->capacity, $request->integer('riders', 1))),
            'offerHours' => max(1, $settings->int(SettingKey::WaitlistOfferHours) ?? 2),
        ]);
    }

    public function store(JoinWaitlistRequest $request, Slot $slot, JoinWaitlist $join): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $join->handle($user, $slot->id, $request->integer('seats'));
        } catch (BookingRejected $e) {
            return back()->withInput()->withErrors(['seats' => $e->getMessage()]);
        }

        return redirect()->route('account.waitlist')->with('status', 'waitlist-joined');
    }

    public function destroy(Request $request, int $entry, LeaveWaitlist $leave): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $own = WaitlistEntry::query()->where('user_id', $user->id)->findOrFail($entry);

        try {
            $leave->handle($own, $user);
        } catch (BookingRejected $e) {
            return redirect()->route('account.waitlist')->withErrors(['waitlist' => $e->getMessage()]);
        }

        return redirect()->route('account.waitlist')->with('status', 'waitlist-left');
    }
}
