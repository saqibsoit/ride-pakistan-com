<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Payments\BankRefund;
use App\Enums\PaymentMethod;
use App\Enums\VoucherOrderStatus;
use App\Enums\VoucherStatus;
use App\Exceptions\BookingRejected;
use App\Exceptions\VoucherRejected;
use App\Models\User;
use App\Models\VoucherOrder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records a refund the team paid back by bank transfer for a gift voucher
 * bought by bank transfer (milestone 10e). Same bank checks as order
 * refunds (BankRefund: admin, reason, bank reference, transfer date). The
 * refund takes money off the voucher's unspent balance (void at zero:
 * VoucherBalance::refund), so it can never be more than what is left on
 * the voucher, nor more than is still refundable on the purchase. A full
 * refund of the purchase marks it refunded. Audit-logged; the voucher
 * sales export shows it.
 */
final class RecordVoucherTransferRefund
{
    public function __construct(
        private readonly VoucherBalance $balance,
        private readonly VoucherOrderStateMachine $orders,
    ) {}

    /** What can still be refunded: the unspent balance, capped by the money not yet refunded. */
    public static function refundableMinor(VoucherOrder $order): int
    {
        $voucher = $order->voucher()->first();
        $unspent = $voucher === null || in_array($voucher->status, [VoucherStatus::Void, VoucherStatus::Expired], true) ? 0 : $voucher->balance_minor;

        return max(0, min($unspent, $order->receivedMinor() - $order->refundedMinor()));
    }

    /** @throws BookingRejected */
    public function handle(VoucherOrder $order, User $admin, int $amountMinor, string $bankReference, CarbonImmutable $transferredOn, string $reason): VoucherOrder
    {
        BankRefund::check($admin, $bankReference, $transferredOn, $reason, null);

        return DB::transaction(function () use ($order, $admin, $amountMinor, $bankReference, $transferredOn, $reason): VoucherOrder {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VoucherOrderStatus::Paid || $locked->payment_method !== PaymentMethod::BankTransfer) {
                throw BookingRejected::notAllowed('Only paid gift vouchers bought by bank transfer can be refunded here.');
            }

            ['reference' => $reference, 'on' => $on, 'reason' => $reason] = BankRefund::check($admin, $bankReference, $transferredOn, $reason, $locked->paid_at);

            foreach ($locked->refundEntries() as $entry) {
                if ($entry['provider_id'] === $reference) {
                    throw BookingRejected::notAllowed('A refund with this bank reference is already recorded on this purchase.');
                }
            }

            $voucher = $locked->voucher()->first();
            $refundable = self::refundableMinor($locked);

            if ($voucher === null || $amountMinor <= 0 || $amountMinor > $refundable) {
                throw BookingRejected::notAllowed('The refund can be at most '.Money::format($refundable).': the unspent balance, and never more than was paid and not yet refunded.');
            }

            try {
                $this->balance->refund($voucher, $amountMinor, $admin, $reason);
            } catch (VoucherRejected $e) {
                throw BookingRejected::notAllowed($e->getMessage());
            }

            $locked->addRefund('bank_transfer', $reference, $amountMinor, ['transferred_on' => $on->toDateString(), 'recorded_by' => $admin->id]);

            if ($locked->refundedMinor() >= $locked->receivedMinor()) {
                $this->orders->transition($locked, VoucherOrderStatus::Refunded, $admin, $reason, ['bank_reference' => $reference]);
            }

            activity('vouchers')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('refunded')
                ->withProperties([
                    'method' => 'bank_transfer',
                    'amount_minor' => $amountMinor,
                    'bank_reference' => $reference,
                    'transferred_on' => $on->toDateString(),
                    'voucher_balance_minor' => $voucher->refresh()->balance_minor,
                    'reason' => $reason,
                ])
                ->log('Voucher purchase '.$locked->reference.' refunded by bank transfer');

            $order->setRawAttributes($locked->refresh()->getAttributes(), true);

            return $order;
        });
    }
}
