<?php

declare(strict_types=1);

namespace App\Actions\Guest;

use App\Actions\Booking\CreateOrder;
use App\Exceptions\BookingRejected;
use App\Exceptions\GuestCheckoutRejected;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * A booking placed without signing in (guest checkout, decided
 * 2026-11-12): the account behind the email (ResolveGuestCustomer), the
 * typed riders as new riders of it (CreateGuestRiders), then the usual
 * order and seat hold (CreateOrder, guest mode: never merged with the
 * account's held orders, no waitlist claim, no welcome discount). The name
 * and WhatsApp number typed are stored on the order.
 *
 * One transaction: a refused booking leaves no account, riders or order
 * behind. The caller checks Turnstile and the rate limits first and gives
 * this browser access to the order afterwards (GuestAccess).
 */
final class PlaceGuestOrder
{
    public function __construct(
        private readonly ResolveGuestCustomer $customers,
        private readonly CreateGuestRiders $riders,
        private readonly CreateOrder $orders,
    ) {}

    /**
     * @param  array{name: string, email: string, whatsapp: string}  $contact  whatsapp in E.164
     * @param  list<array{name: string, age_bracket?: string|null, experience_level?: string|null}>  $riders
     *
     * @throws GuestCheckoutRejected
     * @throws BookingRejected
     */
    public function handle(array $contact, Product $product, ?int $slotId, array $riders, ?int $leadId = null): Order
    {
        return DB::transaction(function () use ($contact, $product, $slotId, $riders, $leadId): Order {
            $user = $this->customers->handle($contact['email'], $contact['name'], $contact['whatsapp']);
            $riderIds = $slotId === null ? [] : $this->riders->handle($user, $riders);

            return $this->orders->handle(
                $user,
                $product,
                $slotId,
                $riderIds,
                $leadId,
                guest: ['name' => trim($contact['name']), 'whatsapp' => $contact['whatsapp']],
            );
        });
    }
}
