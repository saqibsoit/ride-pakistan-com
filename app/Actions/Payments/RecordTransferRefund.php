<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Exceptions\BookingRejected;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a refund the team paid back by bank transfer, for an order the
 * customer paid by bank transfer (milestone 10e). Nothing is sent from
 * here: the admin makes the transfer in online banking, then records the
 * amount, the outgoing transfer's bank reference, the date it was made
 * (Pakistan date) and a reason (BankRefund checks). From there it follows
 * the same rules as a card refund (ApplyRefund): bookings cancelled and
 * credits voided as chosen, the same share of gift voucher and account
 * credit back, the order's status and reports updated, audit-logged.
 * Admins only.
 */
final class RecordTransferRefund
{
    public const MAX_REFERENCE_LENGTH = BankRefund::MAX_REFERENCE_LENGTH;

    public function __construct(private readonly ApplyRefund $refund) {}

    /**
     * @param  list<int>  $cancelBookingIds  partial refunds: bookings to cancel
     * @param  int  $removeCredits  partial refunds: unused credits to take off the pack
     *
     * @throws BookingRejected
     */
    public function handle(
        Order $order,
        User $admin,
        int $amountMinor,
        string $bankReference,
        CarbonImmutable $transferredOn,
        string $reason,
        array $cancelBookingIds = [],
        int $removeCredits = 0,
    ): Order {
        // Checked once without the order (cheap refusals), again with its payment date under lock.
        BankRefund::check($admin, $bankReference, $transferredOn, $reason, null);

        return DB::transaction(function () use ($order, $admin, $amountMinor, $bankReference, $transferredOn, $reason, $cancelBookingIds, $removeCredits): Order {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)
                || $locked->payment_method !== PaymentMethod::BankTransfer) {
                throw BookingRejected::notAllowed('Only paid bank transfer orders can be refunded here.');
            }

            ['reference' => $reference, 'on' => $on, 'reason' => $reason] = BankRefund::check($admin, $bankReference, $transferredOn, $reason, $locked->paid_at);

            $duplicate = Payment::query()
                ->where('order_id', $locked->id)
                ->where('status', PaymentStatus::Refunded)
                ->where('provider_id', $reference)
                ->exists();

            if ($duplicate) {
                throw BookingRejected::notAllowed('A refund with this bank reference is already recorded on this order.');
            }

            $this->refund->handle(
                $locked,
                $admin,
                $amountMinor,
                $reason,
                $cancelBookingIds,
                $removeCredits,
                PaymentProvider::BankTransfer,
                fn (): array => [
                    'provider_id' => $reference,
                    'raw' => ['bank_reference' => $reference, 'transferred_on' => $on->toDateString(), 'recorded_by' => $admin->id],
                    'context' => ['bank_reference' => $reference],
                ],
                $on,
            );

            $order->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $order;
        });
    }
}
