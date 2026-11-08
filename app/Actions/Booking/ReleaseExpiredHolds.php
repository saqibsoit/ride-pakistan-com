<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\BookingHold;
use App\Models\Order;
use App\Support\PageCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every minute (holds:release). Card orders whose hold window passed
 * become expired (logged through OrderStateMachine) and give their seats
 * back. Expired holds already stop counting the moment they expire; this
 * tidies them up and refreshes the cached public pages. Holds on bank
 * transfer orders are handled by the transfer flow (milestone 7).
 */
final class ReleaseExpiredHolds
{
    public function __construct(
        private readonly OrderStateMachine $orders,
        private readonly PageCache $pageCache,
    ) {}

    /** @return int orders expired */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $expired = 0;

        Order::query()
            ->where('status', OrderStatus::PendingPayment)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->each(function (Order $order) use (&$expired): void {
                DB::transaction(function () use ($order, &$expired): void {
                    $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                    // Paid (or otherwise moved on) since we looked: leave it.
                    if ($locked === null || $locked->status !== OrderStatus::PendingPayment) {
                        return;
                    }

                    $this->orders->transition($locked, OrderStatus::Expired, reason: 'Seat hold expired before payment');
                    BookingHold::query()->where('order_id', $locked->id)->delete();
                    $expired++;
                });
            });

        // Stray expired holds whose order no longer waits for payment.
        $stray = BookingHold::query()
            ->where('expires_at', '<=', $now)
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', [OrderStatus::AwaitingTransfer, OrderStatus::AwaitingVerification]))
            ->delete();

        if ($expired > 0 || $stray > 0) {
            $this->pageCache->invalidate();
        }

        return $expired;
    }
}
