<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Booking;
use App\Models\CreditPack;
use App\Models\Order;
use Illuminate\Support\Facades\URL;

/**
 * "Manage your booking" links (guest checkout, decided 2026-11-12). Every
 * booking email carries one for its order, so the customer can see the
 * tickets, pay or upload a transfer, and move or cancel within the usual
 * rules without signing in.
 *
 * The signature covers the order reference and its manage_link_version.
 * Revoking (RevokeManageLinks) raises the version, which stops every link
 * sent so far; how long a link works is ManageLinkWindow, checked live.
 * Opening one grants this browser access to that one order only
 * (GuestAccess) and redirects to the plain /manage/{reference} page, so
 * the signature doesn't stay in the address bar or the history.
 */
final class ManageLink
{
    public static function forOrder(Order $order): string
    {
        return URL::signedRoute('manage.open', ['reference' => $order->reference, 'v' => $order->manage_link_version]);
    }

    /** The package's order, opening straight on "Book with your credits". */
    public static function forPackBooking(CreditPack $pack): string
    {
        $order = $pack->order;

        return URL::signedRoute('manage.open', ['reference' => $order->reference, 'v' => $order->manage_link_version, 'to' => 'book']);
    }

    /**
     * The order behind a booking: the one that paid for it, or the one that
     * bought the package whose credit it used. A no-charge booking an admin
     * added has no order and no link.
     */
    public static function forBooking(Booking $booking): ?string
    {
        $order = self::orderFor($booking);

        return $order === null ? null : self::forOrder($order);
    }

    public static function orderFor(Booking $booking): ?Order
    {
        if ($booking->order_id !== null) {
            return $booking->order;
        }

        return $booking->credit_pack_id !== null ? $booking->creditPack?->order : null;
    }
}
