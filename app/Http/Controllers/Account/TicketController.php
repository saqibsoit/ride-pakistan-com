<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tickets: one per confirmed upcoming booking. The QR code arrives with
 * check-in in milestone 8; for now the ticket shows its code.
 * Other customers' tickets are a 404 (the query is scoped to the owner).
 */
final class TicketController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('account.tickets', [
            'tickets' => $user->bookings()
                ->upcoming()
                ->where('bookings.status', BookingStatus::Confirmed)
                ->with(['slot', 'rider'])
                ->get(),
        ]);
    }

    public function show(Request $request, string $code): View
    {
        /** @var User $user */
        $user = $request->user();

        $booking = $user->bookings()
            ->where('ticket_code', $code)
            ->with(['slot', 'rider', 'order.product', 'creditPack.order.product'])
            ->firstOrFail();

        Gate::authorize('view', $booking);

        return view('account.ticket', ['booking' => $booking]);
    }
}
