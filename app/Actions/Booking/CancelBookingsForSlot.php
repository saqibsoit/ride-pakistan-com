<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Orders\OrderStateMachine;
use App\Actions\Payments\FlagOrderForReview;
use App\Enums\BookingStatus;
use App\Enums\CreditPackStatus;
use App\Enums\OrderStatus;
use App\Models\Booking;
use App\Models\BookingHold;
use App\Models\CreditPack;
use App\Models\Order;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\SlotCancelledNotice;
use App\Support\Notifier;
use App\Support\PageCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * After an admin cancels a slot (SlotCancelled, PLAN §2 / milestone 8):
 *
 * - every confirmed booking is cancelled; a credit booking gets its credit
 *   back whatever the cutoff (the pack must not have been revoked);
 * - a directly paid booking (single or private) is not refunded
 *   automatically: its order is flagged "Needs review" for the admin to
 *   refund or move it;
 * - unpaid orders still holding seats in the slot are cancelled (a late
 *   card payment for them is refunded by the webhook); a transfer already
 *   uploaded is flagged for review;
 * - each affected customer gets one email.
 */
final class CancelBookingsForSlot
{
    public function __construct(
        private readonly SlotLock $slotLock,
        private readonly ChangeBookingStatus $status,
        private readonly CreditPacks $credits,
        private readonly OrderStateMachine $orders,
        private readonly FlagOrderForReview $flag,
        private readonly Notifier $notifier,
        private readonly PageCache $pageCache,
    ) {}

    /** @return int bookings cancelled */
    public function handle(Slot $slot): int
    {
        $actor = $slot->cancelled_by === null ? null : User::query()->find($slot->cancelled_by);
        $reason = 'Session cancelled'.($slot->cancel_reason ? ': '.$slot->cancel_reason : '');

        /** @var array<int, array{bookings: list<int>, credits: int, paid: bool}> $byUser */
        $byUser = [];

        DB::transaction(function () use ($slot, $actor, $reason, &$byUser): void {
            $this->slotLock->acquire($slot->id);

            $bookings = Booking::query()
                ->where('slot_id', $slot->id)
                ->where('status', BookingStatus::Confirmed)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $flagged = [];

            foreach ($bookings as $booking) {
                $entry = $byUser[$booking->user_id] ?? ['bookings' => [], 'credits' => 0, 'paid' => false];
                $entry['bookings'][] = $booking->id;

                if ($booking->credit_pack_id !== null) {
                    $pack = CreditPack::query()->whereKey($booking->credit_pack_id)->lockForUpdate()->first();

                    if ($pack !== null && $pack->status !== CreditPackStatus::Revoked) {
                        $this->credits->giveBack($pack, $booking, $actor, $reason);
                        $entry['credits']++;
                    }
                } elseif ($booking->order_id !== null) {
                    $entry['paid'] = true;

                    if (! isset($flagged[$booking->order_id])) {
                        $order = Order::query()->whereKey($booking->order_id)->lockForUpdate()->first();

                        if ($order !== null) {
                            $this->flag->handle($order, 'slot_cancelled', 'The session on '.$slot->label().' was cancelled. Refund or move this booking.', ['slot_id' => $slot->id]);
                        }

                        $flagged[$booking->order_id] = true;
                    }
                }

                $this->status->handle($booking, BookingStatus::Cancelled, $actor, $reason);
                $byUser[$booking->user_id] = $entry;
            }

            $holdOrders = BookingHold::query()->where('slot_id', $slot->id)->pluck('order_id')->unique()->all();

            foreach (Order::query()->whereIn('id', $holdOrders)->orderBy('id')->lockForUpdate()->get() as $order) {
                if ($order->status->isUnpaid()) {
                    $uploaded = $order->status === OrderStatus::AwaitingVerification;
                    $this->orders->transition($order, OrderStatus::Cancelled, $actor, $reason, ['slot_id' => $slot->id]);

                    if ($uploaded) {
                        $this->flag->handle($order, 'slot_cancelled', 'The session was cancelled while a bank transfer was waiting for review. Refund the transfer if it arrived.', ['slot_id' => $slot->id]);
                    }

                    $entry = $byUser[$order->user_id] ?? ['bookings' => [], 'credits' => 0, 'paid' => false];
                    $entry['paid'] = $entry['paid'] || $uploaded;
                    $byUser[$order->user_id] = $entry;
                }

                BookingHold::query()->where('order_id', $order->id)->where('slot_id', $slot->id)->delete();
            }
        });

        $this->pageCache->invalidate();

        foreach ($byUser as $userId => $entry) {
            $user = User::query()->find($userId);

            if ($user === null) {
                continue;
            }

            /** @var Collection<int, Booking> $cancelled */
            $cancelled = Booking::query()->whereIn('id', $entry['bookings'])->get();

            $this->notifier->send(
                $user,
                new SlotCancelledNotice($slot, $cancelled, $entry['credits'], $entry['paid']),
                Notifier::key(SlotCancelledNotice::type(), $slot, $user),
            );
        }

        return array_sum(array_map(fn (array $e): int => count($e['bookings']), $byUser));
    }
}
