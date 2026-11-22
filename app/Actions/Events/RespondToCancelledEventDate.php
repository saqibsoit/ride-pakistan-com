<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Payments\FlagOrderForReview;
use App\Enums\BalanceStatus;
use App\Enums\BookingStatus;
use App\Enums\SlotStatus;
use App\Exceptions\BookingRejected;
use App\Models\Booking;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\RideEvent;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\EventBookingCancelled;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * We cancelled an event date (decided 2026-11-22): the customer (from the
 * signed link in EventDateCancelled) or an admin chooses for the order:
 *
 * - refund: every person is cancelled and everything paid comes back,
 *   deposit included (card automatically; bank or cash flagged);
 * - move: every person moves to another open date of the same event with
 *   room (RescheduleBooking, slot-change mode, tier caps included); the
 *   balance follows the new date.
 */
final class RespondToCancelledEventDate
{
    public function __construct(
        private readonly CancelBooking $cancel,
        private readonly RescheduleBooking $reschedule,
        private readonly RefundEventMoney $money,
        private readonly FlagOrderForReview $flag,
        private readonly Notifier $notifier,
    ) {}

    /** The order is waiting for this choice. */
    public static function pending(Order $order): bool
    {
        $slotId = $order->meta['event_date_cancelled'] ?? null;

        return is_int($slotId) && Booking::query()->where('order_id', $order->id)->where('slot_id', $slotId)->where('status', BookingStatus::Confirmed)->exists();
    }

    /**
     * Other dates of the event the order could move to.
     *
     * @return Collection<int, Slot>
     */
    public static function alternatives(Order $order): Collection
    {
        $event = RideEvent::query()->find($order->ride_event_id);

        if ($event === null) {
            return new Collection;
        }

        $people = Booking::query()->where('order_id', $order->id)->where('status', BookingStatus::Confirmed)->count();

        return $event->upcomingOccurrences()
            ->filter(fn (Slot $slot): bool => $slot->status === SlotStatus::Open && $slot->seatsFree() >= $people && $event->isBookingOpen($slot))
            ->values();
    }

    /**
     * @return array{refund: int, automatic: int, manual: int}
     *
     * @throws BookingRejected
     */
    public function refund(Order $order, User $actor): array
    {
        $this->assertAllowed($order, $actor);

        $result = DB::transaction(function () use ($order, $actor): array {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $balance = OrderBalance::query()->where('order_id', $locked->id)->lockForUpdate()->first();

            if (! self::pending($locked)) {
                throw BookingRejected::notAllowed('This booking has already been changed.');
            }

            $reason = 'Event date cancelled by us: full refund';

            foreach (Booking::query()->where('order_id', $locked->id)->where('status', BookingStatus::Confirmed)->orderBy('id')->get() as $booking) {
                $this->cancel->handle($booking, $actor, $reason, forEvent: true);
            }

            $fromBalance = $balance?->receivedMinor() ?? 0;
            $fromOrder = CancelEventBooking::paidMinor($locked, null);
            $sent = $fromBalance + $fromOrder > 0
                ? $this->money->handle($locked, $balance, $fromOrder, $fromBalance, $actor, $reason)
                : ['automatic' => 0, 'manual' => 0];

            if ($balance !== null && $balance->isDue()) {
                $balance->status = BalanceStatus::Void;
                $balance->save();
            }

            $this->done($locked, $actor, 'refund', $sent['manual'] > 0);

            return ['refund' => $fromBalance + $fromOrder, ...$sent];
        });

        $order->refresh();
        $this->notifier->send($order->user, new EventBookingCancelled($order, $result['refund'], $result['manual'] > 0, true), Notifier::key(EventBookingCancelled::type(), $order, $order->user));

        return $result;
    }

    /**
     * @throws BookingRejected
     */
    public function move(Order $order, int $targetSlotId, User $actor): void
    {
        $this->assertAllowed($order, $actor);

        DB::transaction(function () use ($order, $targetSlotId, $actor): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! self::pending($locked)) {
                throw BookingRejected::notAllowed('This booking has already been changed.');
            }

            $target = Slot::query()->find($targetSlotId);
            $event = RideEvent::query()->findOrFail($locked->ride_event_id);

            if ($target === null || $target->ride_event_id !== $event->id || ! $event->isBookingOpen($target)) {
                throw BookingRejected::slotNotBookable();
            }

            $first = Booking::query()->where('order_id', $locked->id)->where('status', BookingStatus::Confirmed)->orderBy('id')->firstOrFail();

            // Moves every confirmed booking of this customer on the cancelled date.
            $this->reschedule->handle($first, $target->id, $locked->user, 'Event date cancelled by us: moved', forSlotChange: true);

            $balance = OrderBalance::query()->where('order_id', $locked->id)->lockForUpdate()->first();

            if ($balance !== null && $balance->isDue()) {
                $balance->due_at = $event->balanceOnlineUntil($target);
                $balance->online_until = $balance->due_at;
                $balance->save();
            }

            $this->done($locked, $actor, 'move', false, ['to_slot_id' => $target->id]);
        });
    }

    /**
     * @throws BookingRejected
     */
    private function assertAllowed(Order $order, User $actor): void
    {
        if (! $order->isEvent() || ($order->user_id !== $actor->id && ! $actor->isAdmin())) {
            throw BookingRejected::notAllowed('Please contact us to change this booking.');
        }
    }

    /**
     * @param  array<string, scalar>  $context
     */
    private function done(Order $order, User $actor, string $choice, bool $stillNeedsReview, array $context = []): void
    {
        $meta = $order->meta ?? [];
        unset($meta['event_date_cancelled']);
        $order->meta = $meta;
        $order->save();

        if (! $stillNeedsReview) {
            $this->flag->clear($order);
        }

        activity('bookings')
            ->performedOn($order)
            ->causedBy($actor)
            ->event('event_date_answered')
            ->withProperties(['choice' => $choice, ...$context])
            ->log('Cancelled event date: '.$choice.' for order '.$order->reference);
    }
}
