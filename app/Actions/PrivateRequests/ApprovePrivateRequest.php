<?php

declare(strict_types=1);

namespace App\Actions\PrivateRequests;

use App\Actions\Booking\CreateOrder;
use App\Actions\Slots\CreateManualSlot;
use App\Actions\Slots\ScheduleLock;
use App\Actions\Slots\SlotOverlap;
use App\Enums\PrivateRequestStatus;
use App\Enums\SettingKey;
use App\Exceptions\BookingRejected;
use App\Exceptions\PrivateRequestRejected;
use App\Exceptions\SlotNotCreated;
use App\Models\PrivateSessionRequest;
use App\Models\Slot;
use App\Models\User;
use App\Notifications\PrivateRequestApproved;
use App\Support\Notifier;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An admin approves a private session request (prompt 15B). Under the
 * schedule lock (so two approvals, or an approval and a bulk change,
 * can't both take overlapping times) and in one transaction:
 *
 * 1. the request must still be waiting and inside its answer window;
 * 2. refused when the time clashes with any session that isn't cancelled
 *    (SlotOverlap, with the booking.slot_buffer_minutes buffer) or the
 *    horses can't take the riders that day (HorseDayCapacity);
 * 3. a private-only manual slot is created at that time (optional trainer);
 * 4. an order is created for the riders, priced from the private product,
 *    with a private hold on the whole slot until the payment window ends
 *    (private.pay_hours); every other booking path is blocked by it;
 * 5. the request becomes approved and the customer is emailed the link.
 *
 * Fulfilment happens later, only through FulfilOrder.
 */
final class ApprovePrivateRequest
{
    public function __construct(
        private readonly ScheduleLock $scheduleLock,
        private readonly SlotOverlap $overlap,
        private readonly HorseDayCapacity $horses,
        private readonly CreateManualSlot $createSlot,
        private readonly CreateOrder $createOrder,
        private readonly PrivateRequestStateMachine $states,
        private readonly PrivateRequestRules $rules,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @throws PrivateRequestRejected
     */
    public function handle(PrivateSessionRequest $request, User $admin, ?User $trainer = null): PrivateSessionRequest
    {
        if (! $admin->isAdmin()) {
            throw PrivateRequestRejected::because('Only admins can approve private requests.');
        }

        if ($trainer !== null && ! $trainer->isTrainer()) {
            throw PrivateRequestRejected::because('Only a trainer account can run a session.');
        }

        $approved = $this->scheduleLock->run(fn (): PrivateSessionRequest => DB::transaction(function () use ($request, $admin, $trainer): PrivateSessionRequest {
            $locked = PrivateSessionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($locked->status !== PrivateRequestStatus::Requested) {
                throw PrivateRequestRejected::notOpen();
            }

            if (! $locked->answer_by->isAfter($now)) {
                throw PrivateRequestRejected::because('This request has run past its answer time and is expiring. Ask the customer to send a new one.');
            }

            if (! $locked->starts_at->isAfter($now)) {
                throw PrivateRequestRejected::because('The requested time has passed.');
            }

            $this->assertFree($locked, lock: true);

            try {
                $slot = $this->createSlot->handle(
                    $locked->starts_at,
                    $locked->ends_at,
                    max($locked->riders, $this->settings->int(SettingKey::RidersPerSlot) ?? 6),
                    $admin,
                    privateOnly: true,
                    trainer: $trainer,
                );
            } catch (SlotNotCreated $e) {
                throw PrivateRequestRejected::because($e->getMessage());
            }

            $payBy = $now->addHours($this->rules->payHours());

            try {
                $order = $this->createOrder->handle(
                    $locked->user,
                    $locked->product,
                    $slot->id,
                    array_map('intval', $locked->rider_ids),
                    holdUntil: $payBy,
                    meta: ['private_request_id' => $locked->id, 'pay_by' => $payBy->toIso8601String()],
                );
            } catch (BookingRejected $e) {
                throw PrivateRequestRejected::because($e->getMessage());
            }

            $locked->slot_id = $slot->id;
            $locked->order_id = $order->id;
            $locked->pay_by = $payBy;
            $locked->trainer_id = $trainer?->id;

            return $this->states->transition($locked, PrivateRequestStatus::Approved, $admin, context: [
                'slot_id' => $slot->id,
                'order_id' => $order->id,
                'pay_by' => $payBy->toIso8601String(),
            ]);
        }));

        $approved->load(['user', 'order', 'slot']);
        $this->notifier->send($approved->user, new PrivateRequestApproved($approved), Notifier::key(PrivateRequestApproved::type(), $approved, $approved->user));

        $request->setRawAttributes($approved->getAttributes(), true);

        return $approved;
    }

    /**
     * What would stop an approval right now (for the admin page), or null.
     */
    public function problem(PrivateSessionRequest $request): ?string
    {
        try {
            $this->assertFree($request, lock: false);
        } catch (PrivateRequestRejected $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @throws PrivateRequestRejected
     */
    private function assertFree(PrivateSessionRequest $request, bool $lock): void
    {
        if (Slot::query()->where('starts_at', $request->starts_at)->exists()) {
            throw PrivateRequestRejected::because('A slot already starts at '.PrivateSessionRequest::localTime($request->starts_at).' (it may be cancelled). Decline and suggest another time.');
        }

        $clash = $this->overlap->firstClash($request->starts_at, $request->ends_at, lock: $lock);

        if ($clash !== null) {
            throw PrivateRequestRejected::clash($clash->label(), $this->overlap->bufferMinutes());
        }

        $horses = $this->horses->problem($request->starts_at, $request->riders);

        if ($horses !== null) {
            throw PrivateRequestRejected::because($horses);
        }
    }
}
