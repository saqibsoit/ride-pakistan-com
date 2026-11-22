<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Guest\ResolveGuestCustomer;
use App\Exceptions\BookingRejected;
use App\Exceptions\GuestCheckoutRejected;
use App\Models\Order;
use App\Models\RideEvent;
use Illuminate\Support\Facades\DB;

/**
 * An event booking without signing in (decided 2026-11-22), the guest
 * checkout rules of 2026-11-12: the account behind the email
 * (ResolveGuestCustomer, never changed beyond empty name or WhatsApp), the
 * people typed as new riders of it, then the usual event order and seat
 * hold in guest mode. One transaction: a refusal leaves nothing behind.
 */
final class PlaceGuestEventOrder
{
    public function __construct(
        private readonly ResolveGuestCustomer $customers,
        private readonly CreateEventOrder $orders,
    ) {}

    /**
     * @param  array{name: string, email: string, whatsapp: string}  $contact  whatsapp in E.164
     * @param  list<array{tier_id: int, name: string, answers?: array<string, mixed>}>  $attendees
     * @param  array<string, mixed>  $answers
     *
     * @throws GuestCheckoutRejected
     * @throws BookingRejected
     */
    public function handle(array $contact, RideEvent $event, int $slotId, array $attendees, array $answers, bool $acknowledged, ?int $leadId = null): Order
    {
        return DB::transaction(function () use ($contact, $event, $slotId, $attendees, $answers, $acknowledged, $leadId): Order {
            $user = $this->customers->handle($contact['email'], $contact['name'], $contact['whatsapp']);

            return $this->orders->handle($user, $event, $slotId, $attendees, $answers, $acknowledged, $leadId, guest: ['name' => trim($contact['name']), 'whatsapp' => $contact['whatsapp']]);
        });
    }
}
