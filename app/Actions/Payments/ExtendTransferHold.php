<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\SlotLock;
use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Models\BookingHold;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An admin gives a bank transfer order more time (reason required,
 * logged). Under the slot lock: a hold that had already lapsed is only
 * renewed if the seats are still free.
 */
final class ExtendTransferHold
{
    public function __construct(private readonly SlotLock $slotLock) {}

    /** @throws BookingRejected */
    public function handle(Order $order, User $admin, CarbonImmutable $until, string $reason): Order
    {
        $reason = (string) OrderStateMachine::cleanReason($reason);

        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can extend holds.');
        }

        if ($reason === '') {
            throw BookingRejected::notAllowed('Give a reason for the extension.');
        }

        return DB::transaction(function () use ($order, $admin, $until, $reason): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if (! in_array($locked->status, [OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification], true)) {
                throw BookingRejected::notAllowed('Only orders waiting for a bank transfer can be extended.');
            }

            if (! $until->isAfter($now) || ($locked->expires_at !== null && ! $until->isAfter($locked->expires_at))) {
                throw BookingRejected::notAllowed('The new deadline must be in the future and later than the current one.');
            }

            $locked->load(['product', 'items']);
            $private = $locked->isPrivate();

            /** @var array<int, list<int>> $ridersBySlot */
            $ridersBySlot = [];

            foreach ($locked->items as $item) {
                /** @var OrderItem $item */
                if ($item->slot_id !== null && $item->rider_id !== null) {
                    $ridersBySlot[$item->slot_id][] = $item->rider_id;
                }
            }

            ksort($ridersBySlot);

            foreach ($ridersBySlot as $slotId => $riderIds) {
                $slot = $this->slotLock->acquire($slotId, ignoreOrderId: $locked->id);

                if ($slot->slot->starts_at->isBefore($until)) {
                    throw BookingRejected::notAllowed('The deadline can\'t be after the session starts ('.$slot->slot->label().').');
                }

                $hold = BookingHold::query()->where('order_id', $locked->id)->where('slot_id', $slotId)->lockForUpdate()->first();

                if ($hold === null || ! $hold->expires_at->isAfter($now)) {
                    $slot->assertRoomFor($riderIds, $private);
                }

                if ($hold === null) {
                    BookingHold::query()->create([
                        'slot_id' => $slotId,
                        'order_id' => $locked->id,
                        'seats' => $private ? $slot->slot->capacity : count($riderIds),
                        'is_private' => $private,
                        'expires_at' => $until,
                    ]);
                } elseif ($hold->expires_at->isBefore($until)) {
                    $hold->expires_at = $until;
                    $hold->save();
                }
            }

            $old = $locked->expires_at;
            $locked->expires_at = $until;
            $locked->save();

            activity('payments')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('transfer_hold_extended')
                ->withProperties(['from' => $old?->toIso8601String(), 'to' => $until->toIso8601String(), 'reason' => $reason])
                ->log('Transfer deadline extended for order '.$locked->reference);

            $order->setRawAttributes($locked->getAttributes(), true);

            return $order;
        });
    }
}
