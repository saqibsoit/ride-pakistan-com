<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Events\CancelEventBooking;
use App\Actions\Events\CreateBalanceCheckout;
use App\Actions\Events\RespondToCancelledEventDate;
use App\Exceptions\BookingRejected;
use App\Http\Middleware\EnsureOrderAccess;
use App\Support\Events\EventBooking;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Event bookings on "Manage your booking" (decided 2026-11-22), for the
 * signed-in owner or a browser with this order's link: pay the balance
 * online, cancel (with what comes back shown first), and choose a full
 * refund or another date after we cancelled one. Each acts as the order's
 * customer.
 */
final class ManageEventController extends Controller
{
    public function payBalance(Request $request, CreateBalanceCheckout $checkout): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        try {
            return redirect()->away($checkout->handle($order));
        } catch (BookingRejected $e) {
            return redirect()->route('manage.show', $order->reference)->withErrors(['booking' => $e->getMessage()]);
        }
    }

    public function confirmCancel(Request $request, CancelEventBooking $cancel): View|RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        if (! $cancel->canCancel($order, $order->user)) {
            return redirect()->route('manage.show', $order->reference);
        }

        return view('manage.event-cancel', [
            'order' => $order,
            'event' => EventBooking::for($order),
            'quote' => $cancel->quote($order),
        ]);
    }

    public function cancel(Request $request, CancelEventBooking $cancel): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        try {
            $result = $cancel->handle($order, $order->user);
        } catch (BookingRejected $e) {
            return redirect()->route('manage.show', $order->reference)->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('manage.show', $order->reference)->with('status', $result['refund'] > 0
            ? 'Your booking is cancelled. We’re refunding '.Money::format($result['refund'], $order->currency).'.'
            : 'Your booking is cancelled.');
    }

    public function refundCancelledDate(Request $request, RespondToCancelledEventDate $respond): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);

        try {
            $result = $respond->refund($order, $order->user);
        } catch (BookingRejected $e) {
            return redirect()->route('manage.show', $order->reference)->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('manage.show', $order->reference)->with('status', 'We’re refunding '.Money::format($result['refund'], $order->currency).', deposit included.');
    }

    public function moveCancelledDate(Request $request, RespondToCancelledEventDate $respond): RedirectResponse
    {
        $order = EnsureOrderAccess::order($request);
        $data = $request->validate(['slot' => ['required', 'integer']]);

        try {
            $respond->move($order, (int) $data['slot'], $order->user);
        } catch (BookingRejected $e) {
            return redirect()->route('manage.show', $order->reference)->withErrors(['booking' => $e->getMessage()]);
        }

        return redirect()->route('manage.show', $order->reference)->with('status', 'You’re booked on the new date. Your tickets stay the same.');
    }
}
