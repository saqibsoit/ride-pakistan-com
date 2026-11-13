<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\CancellationCutoff;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Guest\RevokeManageLinks;
use App\Auth\LoginCodes\LoginCodeChannel;
use App\Enums\BookingStatus;
use App\Enums\CalendarMethod;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Http\Middleware\EnsureOrderAccess;
use App\Http\Requests\ManageRescheduleRequest;
use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Slot;
use App\Notifications\ManageLinkReissued;
use App\Support\Availability;
use App\Support\Calendar\GoogleCalendarLink;
use App\Support\Calendar\IcsCalendar;
use App\Support\GuestAccess;
use App\Support\ManageLinkWindow;
use App\Support\Notifier;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * "Manage your booking" (guest checkout, decided 2026-11-12): one order,
 * without signing in.
 *
 * - GET /manage/{reference}/open is the signed link from the emails. A
 *   current link grants this browser access to that one order
 *   (GuestAccess) and redirects to the plain page. A tampered, revoked or
 *   expired link shows the same "ask for a new link" page as a reference
 *   nobody may open.
 * - /manage/{reference}: status, paying, tickets, calendar files, and
 *   moving or cancelling credit bookings within the usual cutoff (the same
 *   rules and actions as the account pages, as the order's own customer).
 *   Paid single and private bookings change only through us.
 * - "Stop these links": RevokeManageLinks, then a new link by email.
 * - /manage: ask for a new link with the email and order reference. The
 *   answer is the same whether or not they match.
 */
final class ManageOrderController extends Controller
{
    public function open(Request $request, string $reference, GuestAccess $guests, ManageLinkWindow $window): View|RedirectResponse|Response
    {
        $order = Order::query()->where('reference', $reference)->first();

        if (! $request->hasValidSignature()
            || $order === null
            || $request->integer('v') !== $order->manage_link_version
            || ! $window->isOpen($order)) {
            return $this->closed();
        }

        $guests->grantOrder($order);

        return $request->query('to') === 'book' && $order->creditPack !== null
            ? redirect()->route('manage.book', $order->reference)
            : redirect()->route('manage.show', $order->reference);
    }

    public function show(Request $request, CancellationCutoff $cutoff, GoogleCalendarLink $google, ManageLinkWindow $window): View
    {
        $order = EnsureOrderAccess::order($request);
        $order->load(['product', 'items.slot', 'items.rider', 'discounts', 'creditPack']);
        $bookings = $this->bookings($order);

        return view('manage.show', [
            'order' => $order,
            'bookings' => $bookings,
            'pack' => $order->creditPack,
            'unpaid' => $order->status->isUnpaid(),
            'transfer' => in_array($order->status, [OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification], true),
            'changeable' => $bookings->mapWithKeys(fn (Booking $b): array => [$b->id => Gate::forUser($order->user)->allows('change', $b)])->all(),
            'cutoffHours' => $cutoff->hours(),
            'google' => $bookings->mapWithKeys(fn (Booking $b): array => [$b->id => $google->for($b)])->all(),
            'endsAt' => $window->endsAt($order),
        ]);
    }

    /** Book with this order's package credits (App\Livewire\Manage\PackBooking). */
    public function book(Request $request): View|RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $pack = $order->creditPack;

        if ($pack === null || ! $pack->isUsable() || $pack->creditsRemaining() < 1) {
            return redirect()->route('manage.show', $order->reference);
        }

        return view('manage.book', ['order' => $order]);
    }

    public function calendar(Request $request, string $reference, string $ticket, IcsCalendar $ics): Response
    {
        $booking = $this->booking($request, $ticket);
        $method = $booking->status === BookingStatus::Cancelled ? CalendarMethod::Cancel : CalendarMethod::Publish;

        return response($ics->forBookings([$booking], $method), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.IcsCalendar::filename($booking).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function confirmCancel(Request $request, string $reference, string $ticket, CancellationCutoff $cutoff): View
    {
        $booking = $this->changeable($request, $ticket);

        return view('manage.cancel', [
            'order' => EnsureOrderAccess::order($request),
            'booking' => $booking,
            'creditBack' => $cutoff->isBefore($booking->slot),
            'cutoffHours' => $cutoff->hours(),
            'deadline' => $cutoff->deadline($booking->slot),
        ]);
    }

    public function cancel(Request $request, string $reference, string $ticket, CancelBooking $cancel): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $booking = $this->changeable($request, $ticket);

        try {
            $returned = $cancel->handle($booking, $order->user);
        } catch (BookingRejected $e) {
            return redirect()->route('manage.show', $order->reference)->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('manage.show', $order->reference)->with('status', $returned ? 'Your session is cancelled and the credit is back on your package.' : 'Your session is cancelled. It was after the cutoff, so the credit is used.');
    }

    public function editReschedule(Request $request, string $reference, string $ticket, Availability $availability, CancellationCutoff $cutoff): View
    {
        $booking = $this->changeable($request, $ticket);
        $pack = CreditPack::query()->find($booking->credit_pack_id);

        return view('manage.reschedule', [
            'order' => EnsureOrderAccess::order($request),
            'booking' => $booking,
            'slots' => $availability->upcoming(24, Availability::TYPE_GROUP, 8)
                ->reject(fn (Slot $slot): bool => $slot->id === $booking->slot_id)
                ->filter(fn (Slot $slot): bool => $pack === null || $pack->covers($slot))
                ->values(),
            'canMove' => $cutoff->isBefore($booking->slot),
            'cutoffHours' => $cutoff->hours(),
        ]);
    }

    public function reschedule(ManageRescheduleRequest $request, string $reference, string $ticket, RescheduleBooking $reschedule): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $booking = $this->changeable($request, $ticket);

        try {
            $reschedule->handle($booking, $request->integer('slot'), $order->user);
        } catch (BookingRejected $e) {
            return back()->withErrors(['slot' => $e->getMessage()]);
        }

        return redirect()->route('manage.show', $order->reference)->with('status', 'Your session has moved. We’ve emailed the new details.');
    }

    /** "Stop these links": every link sent so far stops working; a new one is emailed. */
    public function revoke(Request $request, RevokeManageLinks $revoke): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $revoke->handle($order, null);

        return redirect()->route('manage.link')->with('status', 'revoked');
    }

    public function linkForm(): View
    {
        return view('manage.link', ['closed' => false]);
    }

    /** Same answer whether or not the email and reference match an order (nothing to probe). */
    public function sendLink(Request $request, LoginCodeChannel $channel, ManageLinkWindow $window, Notifier $notifier): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'reference' => ['required', 'string', 'max:20'],
        ], [
            'email.*' => 'Enter the email address you booked with.',
            'reference.*' => 'Enter your order reference, for example RP-7KD2QX.',
        ]);

        $order = Order::query()->where('reference', strtoupper(trim((string) $data['reference'])))->with('user')->first();

        if ($order !== null && $order->user->email === $channel->normalise((string) $data['email']) && $window->isOpen($order)) {
            $notifier->send($order->user, new ManageLinkReissued($order, revoked: false));
        }

        return redirect()->route('manage.link')->with('status', 'sent');
    }

    /** The page for a link that doesn't work (any reason, the same page). */
    private function closed(): Response
    {
        return response()->view('manage.link', ['closed' => true], 403);
    }

    /**
     * The order's bookings: those it paid for and those its package's
     * credits booked.
     *
     * @return Collection<int, Booking>
     */
    private function bookings(Order $order): Collection
    {
        $packId = $order->creditPack?->id;

        return Booking::query()
            ->where(fn ($q) => $q->where('order_id', $order->id)->when($packId !== null, fn ($q) => $q->orWhere('credit_pack_id', $packId)))
            ->with(['slot', 'rider'])
            ->get()
            ->sortBy(fn (Booking $b): int => $b->slot->starts_at->getTimestamp())
            ->values();
    }

    private function booking(Request $request, string $ticket): Booking
    {
        $booking = $this->bookings(EnsureOrderAccess::order($request))->firstWhere('ticket_code', $ticket);

        abort_if($booking === null, 404);

        return $booking;
    }

    /** A booking of this order the customer may move or cancel (BookingPolicy::change, as the order's customer). */
    private function changeable(Request $request, string $ticket): Booking
    {
        $booking = $this->booking($request, $ticket);

        abort_unless(Gate::forUser(EnsureOrderAccess::order($request)->user)->allows('change', $booking), 404);

        return $booking;
    }
}
