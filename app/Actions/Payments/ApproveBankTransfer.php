<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Booking\FulfilOrder;
use App\Enums\BankTransferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Events\OrderPaid;
use App\Exceptions\BookingRejected;
use App\Models\BankTransfer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\OrderNotFulfilled;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An admin approves a bank transfer (PLAN §3.2 step 4). Fulfilment goes
 * through FulfilOrder, exactly as for a card payment. In one transaction:
 * the transfer is marked approved with the verifier and time, the payment
 * is recorded, and the order is fulfilled. Approving twice changes
 * nothing. If the booking can no longer be given (FulfilOrder says no:
 * the slot was closed, cancelled, filled up or booked privately after
 * the hold ran out), the approval is rolled back, the order is flagged
 * "Needs review" and the customer is emailed once (OrderNotFulfilled);
 * the BookingRejected is rethrown so the admin sees why and can refund
 * by bank or move the booking.
 */
final class ApproveBankTransfer
{
    public function __construct(
        private readonly FulfilOrder $fulfil,
        private readonly FlagOrderForReview $flag,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @return bool false when it had already been approved
     *
     * @throws BookingRejected
     */
    public function handle(BankTransfer $transfer, User $admin): bool
    {
        if (! $admin->isAdmin()) {
            throw BookingRejected::notAllowed('Only admins can approve transfers.');
        }

        $unfulfillable = null;

        try {
            $approved = $this->approve($transfer, $admin, $unfulfillable);
        } catch (BookingRejected $e) {
            if ($e === $unfulfillable) {
                $this->reportUnfulfillable($transfer, $admin, $e);
            }

            throw $e;
        }

        if ($approved) {
            event(new OrderPaid(Order::query()->findOrFail($transfer->order_id)));
        }

        return $approved;
    }

    /**
     * @param-out BookingRejected|null $unfulfillable  set when FulfilOrder refused
     *
     * @throws BookingRejected
     */
    private function approve(BankTransfer $transfer, User $admin, ?BookingRejected &$unfulfillable): bool
    {
        return DB::transaction(function () use ($transfer, $admin, &$unfulfillable): bool {
            $order = Order::query()->whereKey($transfer->order_id)->lockForUpdate()->firstOrFail();
            $locked = BankTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BankTransferStatus::Approved) {
                return false;
            }

            if ($locked->status !== BankTransferStatus::Submitted || $order->status !== OrderStatus::AwaitingVerification) {
                throw BookingRejected::notAllowed('This transfer is not waiting for review.');
            }

            $now = CarbonImmutable::now();

            $locked->status = BankTransferStatus::Approved;
            $locked->verified_by = $admin->id;
            $locked->verified_at = $now;
            $locked->save();

            Payment::query()->create([
                'order_id' => $order->id,
                'provider' => PaymentProvider::BankTransfer,
                'provider_id' => $locked->transaction_ref,
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'status' => PaymentStatus::Succeeded,
                'raw' => ['bank_transfer_id' => $locked->id, 'verified_by' => $admin->id],
            ]);

            try {
                $this->fulfil->handle($order, $admin, ['bank_transfer_id' => $locked->id]);
            } catch (BookingRejected $e) {
                $unfulfillable = $e; // rolls the approval back

                throw $e;
            }

            activity('payments')
                ->performedOn($order)
                ->causedBy($admin)
                ->event('transfer_approved')
                ->withProperties(['bank_transfer_id' => $locked->id, 'amount_minor' => $order->amount_minor])
                ->log('Bank transfer approved for order '.$order->reference);

            $transfer->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /** The money is in but the booking can't be given: flag it for the team and tell the customer. */
    private function reportUnfulfillable(BankTransfer $transfer, User $admin, BookingRejected $e): void
    {
        DB::transaction(function () use ($transfer, $admin, $e): void {
            $order = Order::query()->whereKey($transfer->order_id)->lockForUpdate()->firstOrFail();

            $this->flag->handle($order, 'transfer_unfulfillable', 'Bank transfer received but the booking could not be confirmed ('.$e->getMessage().'). Refund it by bank or move the booking.', [
                'bank_transfer_id' => $transfer->id,
                'admin_id' => $admin->id,
                'reason' => $e->reason,
            ]);

            $this->notifier->send($order->user, new OrderNotFulfilled($order, refunded: false), Notifier::key(OrderNotFulfilled::type(), $order, $order->user));
        });
    }
}
