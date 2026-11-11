<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Actions\Slots\ChangeSlotStatus;
use App\Enums\OrderStatus;
use App\Enums\PrivateRequestStatus;
use App\Enums\SlotStatus;
use App\Models\Order;
use App\Models\PrivateSessionRequest;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\PrivateRequestLapsed;
use App\Notifications\PrivateSessionAssigned;
use App\Support\Notifier;

/**
 * Keeps an approved private request in step with its order (prompt 15B),
 * called by OrderStateMachine inside the order's transaction:
 *
 * - order paid → request paid; the trainer picked at approval is emailed;
 * - order expired, cancelled or rejected → request lapsed, the private
 *   slot is cancelled (released for other requests) and the customer is
 *   told once. A card payment that still arrives later is refunded by
 *   FulfilOrder's slot re-check.
 */
final class SyncPrivateRequest
{
    public function __construct(
        private readonly PrivateRequestStateMachine $states,
        private readonly ChangeSlotStatus $slots,
        private readonly Notifier $notifier,
    ) {}

    public function handle(Order $order, OrderStatus $to, ?User $actor = null): void
    {
        $requestId = $order->meta['private_request_id'] ?? null;

        if (! is_numeric($requestId)) {
            return;
        }

        $request = PrivateSessionRequest::query()->whereKey((int) $requestId)->lockForUpdate()->first();

        if ($request === null || $request->status !== PrivateRequestStatus::Approved || $request->order_id !== $order->id) {
            return;
        }

        if ($to === OrderStatus::Paid) {
            $this->states->transition($request, PrivateRequestStatus::Paid, $actor, context: ['order_id' => $order->id]);

            $trainer = $request->trainer;

            if ($trainer !== null) {
                $this->notifier->send($trainer, new PrivateSessionAssigned($request), Notifier::key(PrivateSessionAssigned::type(), $request, $trainer));
            }

            return;
        }

        if (! in_array($to, [OrderStatus::Expired, OrderStatus::Cancelled, OrderStatus::Rejected], true)) {
            return;
        }

        $this->states->transition($request, PrivateRequestStatus::Lapsed, $actor, 'Order '.mb_strtolower($to->label()), ['order_id' => $order->id]);

        $slot = $request->slot_id === null ? null : Slot::query()->find($request->slot_id);

        if ($slot !== null && $slot->status !== SlotStatus::Cancelled) {
            $this->slots->handle($slot, SlotStatus::Cancelled, $actor, 'Private request '.$request->reference.' not paid in time');
        }

        $request->loadMissing('user');
        $this->notifier->send($request->user, new PrivateRequestLapsed($request, $to), Notifier::key(PrivateRequestLapsed::type(), $request, $request->user));
    }
}
