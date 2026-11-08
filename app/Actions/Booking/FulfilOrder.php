<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\CreditReason;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditLedgerEntry;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only way an order turns into bookings and credits (AGENTS.md: called
 * by the Stripe webhook handler or the bank transfer approval). Idempotent:
 * a second call for a paid order changes nothing.
 *
 * In one transaction: order locked and moved to paid (OrderStateMachine),
 * each held slot locked, holds turned into bookings with unique ticket
 * codes, a credit pack created for packages (valid from now, for the
 * product's validity) with ledger entries, and the holds removed. If a
 * hold ran out before payment arrived, the seat is taken again only if
 * the slot still has room; otherwise BookingRejected is thrown and
 * nothing is written (the caller refunds).
 */
final class FulfilOrder
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly SlotLock $slotLock,
        private readonly GenerateTicketCode $tickets,
        private readonly CreditPacks $credits,
    ) {}

    /**
     * @param  array<string, scalar|null>  $context  audit details (Stripe event id, transfer id)
     *
     * @throws BookingRejected
     */
    public function handle(Order $order, ?User $actor = null, array $context = []): Order
    {
        return DB::transaction(function () use ($order, $actor, $context): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true)) {
                $order->setRawAttributes($locked->getAttributes(), true);

                return $order;
            }

            if (! in_array($locked->status, [OrderStatus::PendingPayment, OrderStatus::AwaitingVerification], true)) {
                throw BookingRejected::orderNotFulfillable($locked->reference, $locked->status->value);
            }

            $locked->load(['product', 'items']);
            $now = CarbonImmutable::now();

            /** @var array<int, list<OrderItem>> $seatsBySlot */
            $seatsBySlot = [];

            foreach ($locked->items as $item) {
                if ($item->slot_id !== null && $item->rider_id !== null) {
                    $seatsBySlot[$item->slot_id][] = $item;
                }
            }

            ksort($seatsBySlot);
            $private = $locked->isPrivate();

            // Every slot locked (in id order) and checked before anything is written.
            foreach ($seatsBySlot as $slotId => $items) {
                $slot = $this->slotLock->acquire($slotId, ignoreOrderId: $locked->id);

                $hold = BookingHold::query()
                    ->where('order_id', $locked->id)
                    ->where('slot_id', $slotId)
                    ->where('expires_at', '>', $now)
                    ->lockForUpdate()
                    ->first();

                if ($hold === null) {
                    $slot->assertRoomFor(array_map(fn (OrderItem $i): int => (int) $i->rider_id, $items), $private);
                }
            }

            $this->orders->transition($locked, OrderStatus::Paid, $actor, context: $context);

            $pack = $locked->isPackage()
                ? $this->grantPack($locked, $now, $actor)
                : null;

            foreach ($seatsBySlot as $slotId => $items) {
                foreach ($items as $item) {
                    $booking = Booking::query()->create([
                        'slot_id' => $slotId,
                        'order_id' => $locked->id,
                        'rider_id' => $item->rider_id,
                        'user_id' => $locked->user_id,
                        'credit_pack_id' => $pack?->id,
                        'is_private' => $private,
                        'ticket_code' => $this->tickets->handle(),
                    ]);

                    if ($pack !== null) {
                        $this->credits->spend($pack, $booking, $actor);
                    }
                }
            }

            BookingHold::query()->where('order_id', $locked->id)->delete();

            activity('bookings')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('fulfilled')
                ->withProperties([...$context, 'bookings' => array_sum(array_map('count', $seatsBySlot)), 'credit_pack_id' => $pack?->id])
                ->log('Order '.$locked->reference.' fulfilled');

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }

    private function grantPack(Order $order, CarbonImmutable $now, ?User $actor): CreditPack
    {
        // Terms as bought (order snapshot), never the product's current ones.
        $credits = $order->sessionsCount();

        $pack = CreditPack::query()->create([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'credits_total' => $credits,
            'valid_from' => $now,
            'expires_at' => $now->addDays((int) $order->validityDays()),
        ]);

        CreditLedgerEntry::query()->create([
            'credit_pack_id' => $pack->id,
            'delta' => $credits,
            'reason' => CreditReason::Granted,
            'note' => 'Order '.$order->reference,
            'actor_id' => $actor?->id,
        ]);

        return $pack;
    }
}
