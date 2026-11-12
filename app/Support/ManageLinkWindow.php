<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Slot;
use Carbon\CarbonImmutable;

/**
 * How long a "Manage your booking" link (and a guest's own session access)
 * works for an order (guest checkout, decided 2026-11-12): until
 * DAYS_AFTER days after the latest of
 *
 * - the end of every session the order paid or holds seats for, and of
 *   every booking made with the package it bought (moved sessions count at
 *   their new time, so the window follows them);
 * - the package's expiry;
 * - while the order is unpaid, its payment deadline;
 * - for an order that ended unpaid (expired, cancelled, rejected), when
 *   it ended.
 *
 * It is worked out from the order as it is now on every request, never
 * frozen into the link.
 */
final class ManageLinkWindow
{
    public const DAYS_AFTER = 7;

    public function endsAt(Order $order): CarbonImmutable
    {
        $times = [CarbonImmutable::parse($order->created_at)];

        $itemEnd = Slot::query()
            ->whereIn('id', OrderItem::query()->where('order_id', $order->id)->whereNotNull('slot_id')->select('slot_id'))
            ->max('ends_at');

        $pack = $order->creditPack()->first();

        $bookingEnd = Slot::query()
            ->whereIn('id', Booking::query()
                ->where(fn ($q) => $q->where('order_id', $order->id)->when($pack !== null, fn ($q) => $q->orWhere('credit_pack_id', $pack?->id)))
                ->select('slot_id'))
            ->max('ends_at');

        foreach ([$itemEnd, $bookingEnd] as $end) {
            if ($end !== null) {
                $times[] = CarbonImmutable::parse($end);
            }
        }

        if ($pack !== null) {
            $times[] = CarbonImmutable::parse($pack->expires_at);
        }

        if ($order->status === OrderStatus::AwaitingVerification) {
            // An uploaded transfer never expires on its own (PLAN §3.2).
            $times[] = CarbonImmutable::now();
        }

        if ($order->status->isUnpaid() && $order->expires_at !== null) {
            $times[] = $order->expires_at;
        } elseif ($order->status->isTerminal() && $order->paid_at === null && $order->updated_at !== null) {
            $times[] = CarbonImmutable::parse($order->updated_at);
        }

        $latest = array_reduce($times, fn (CarbonImmutable $carry, CarbonImmutable $time): CarbonImmutable => $time->greaterThan($carry) ? $time : $carry, $times[0]);

        return $latest->addDays(self::DAYS_AFTER);
    }

    public function isOpen(Order $order, ?CarbonImmutable $now = null): bool
    {
        return ($now ?? CarbonImmutable::now())->lessThan($this->endsAt($order));
    }
}
