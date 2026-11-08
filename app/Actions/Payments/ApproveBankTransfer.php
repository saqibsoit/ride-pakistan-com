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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An admin approves a bank transfer (PLAN §3.2 step 4). Fulfilment goes
 * through FulfilOrder, exactly as for a card payment. In one transaction:
 * the transfer is marked approved with the verifier and time, the payment
 * is recorded, and the order is fulfilled. Approving twice changes
 * nothing; if the seats can no longer be given (FulfilOrder says no),
 * nothing is written and the admin sees why.
 */
final class ApproveBankTransfer
{
    public function __construct(private readonly FulfilOrder $fulfil) {}

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

        $approved = DB::transaction(function () use ($transfer, $admin): bool {
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

            $this->fulfil->handle($order, $admin, ['bank_transfer_id' => $locked->id]);

            activity('payments')
                ->performedOn($order)
                ->causedBy($admin)
                ->event('transfer_approved')
                ->withProperties(['bank_transfer_id' => $locked->id, 'amount_minor' => $order->amount_minor])
                ->log('Bank transfer approved for order '.$order->reference);

            $transfer->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($approved) {
            event(new OrderPaid(Order::query()->findOrFail($transfer->order_id)));
        }

        return $approved;
    }
}
