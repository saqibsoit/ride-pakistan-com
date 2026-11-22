<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Booking\CancelBooking;
use App\Enums\BalanceStatus;
use App\Enums\BookingStatus;
use App\Enums\OrderStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\EventBookingCancelled;
use App\Support\Events\EventMoney;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A customer cancels their event booking (decided 2026-11-22), from the
 * manage page or their account; an admin can do it for them with the same
 * rules. Every person on the order is cancelled.
 *
 * Money: deposits are non-refundable. Before the event's cutoff (default 7
 * days) the customer gets back what they paid minus the event's
 * non-refundable share of the price, so with the usual settings the
 * deposit is kept and a paid balance comes back; a full-payment event with
 * a 0 % share refunds everything. After the cutoff nothing comes back. An
 * unpaid balance is no longer owed. The terms are the ones the order was
 * booked on (order meta).
 */
final class CancelEventBooking
{
    public function __construct(
        private readonly CancelBooking $cancel,
        private readonly RefundEventMoney $money,
        private readonly Notifier $notifier,
    ) {}

    /**
     * What cancelling now would refund, for the confirmation screen.
     *
     * @return array{refund: int, paid: int, before_cutoff: bool, cutoff: CarbonImmutable|null}
     */
    public function quote(Order $order, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $order->loadMissing(['balance', 'rideEvent']);
        $occurrence = $this->occurrence($order);
        $cutoff = $occurrence === null ? null : $occurrence->starts_at->subDays($this->cutoffDays($order));
        $before = $cutoff !== null && $now->isBefore($cutoff);
        $paid = self::paidMinor($order, $order->balance);

        return [
            'refund' => EventMoney::customerRefund($paid, $order->priceMinor(), $this->nonRefundablePercent($order), $before),
            'paid' => $paid,
            'before_cutoff' => $before,
            'cutoff' => $cutoff,
        ];
    }

    /** The customer (or an admin) may cancel this order now. */
    public function canCancel(Order $order, User $actor): bool
    {
        if (! $order->isEvent() || ($order->user_id !== $actor->id && ! $actor->isAdmin())) {
            return false;
        }

        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
            return false;
        }

        $occurrence = $this->occurrence($order);

        return $occurrence !== null
            && $occurrence->starts_at->isFuture()
            && Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->exists();
    }

    /**
     * @return array{refund: int, automatic: int, manual: int}
     *
     * @throws BookingRejected
     */
    public function handle(Order $order, User $actor, ?string $reason = null): array
    {
        if (! $this->canCancel($order, $actor)) {
            throw BookingRejected::notAllowed('This booking can’t be cancelled online. Please contact us.');
        }

        $reason = trim((string) $reason) !== '' ? trim((string) $reason) : ($actor->id === $order->user_id ? 'Cancelled by the customer' : 'Cancelled for the customer');

        $result = DB::transaction(function () use ($order, $actor, $reason): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $balance = OrderBalance::query()->where('order_id', $locked->id)->lockForUpdate()->first();
            $locked->setRelation('balance', $balance);

            $quote = $this->quote($locked);

            foreach (Booking::query()->where('order_id', $locked->id)->where('status', BookingStatus::Confirmed)->orderBy('id')->get() as $booking) {
                $this->cancel->handle($booking, $actor, $reason, forEvent: true);
            }

            // Balance first (it is the money paid last), then the order's own payment.
            $fromBalance = min($quote['refund'], $balance?->receivedMinor() ?? 0);
            $fromOrder = $quote['refund'] - $fromBalance;

            $sent = $quote['refund'] > 0
                ? $this->money->handle($locked, $balance, $fromOrder, $fromBalance, $actor, $reason)
                : ['automatic' => 0, 'manual' => 0];

            if ($balance !== null && $balance->isDue()) {
                $balance->status = BalanceStatus::Void;
                $balance->save();
            }

            activity('bookings')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('event_cancelled')
                ->withProperties([
                    'refund_minor' => $quote['refund'],
                    'automatic_minor' => $sent['automatic'],
                    'manual_minor' => $sent['manual'],
                    'before_cutoff' => $quote['before_cutoff'],
                    'reason' => $reason,
                ])
                ->log('Event booking '.$locked->reference.' cancelled');

            return ['refund' => $quote['refund'], ...$sent];
        });

        $order->refresh();
        $this->notifier->send($order->user, new EventBookingCancelled($order, $result['refund'], $result['manual'] > 0, false), Notifier::key(EventBookingCancelled::type(), $order, $order->user));

        return $result;
    }

    /** Money received for the order (deposit or full payment, plus any balance), net of refunds. */
    public static function paidMinor(Order $order, ?OrderBalance $balance): int
    {
        $orderCash = in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)
            ? max(0, $order->amount_minor - $order->amount_refunded_minor)
            : 0;

        return $orderCash + ($balance?->receivedMinor() ?? 0);
    }

    private function occurrence(Order $order): ?Slot
    {
        $slotId = $order->items()->whereNotNull('slot_id')->value('slot_id');

        return $slotId === null ? null : Slot::query()->whereKey((int) $slotId)->first();
    }

    private function cutoffDays(Order $order): int
    {
        return (int) ($order->meta['cancellation_cutoff_days'] ?? RideEvent::query()->whereKey($order->ride_event_id)->value('cancellation_cutoff_days') ?? 7);
    }

    private function nonRefundablePercent(Order $order): int
    {
        return (int) ($order->meta['non_refundable_percent'] ?? RideEvent::query()->whereKey($order->ride_event_id)->value('non_refundable_percent') ?? 0);
    }
}
