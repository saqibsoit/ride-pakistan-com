<?php

declare(strict_types=1);

namespace App\Actions\Vouchers;

use App\Actions\Orders\OrderStateMachine;
use App\Enums\VoucherOrderStatus;
use App\Exceptions\VoucherRejected;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrder;
use App\Notifications\VoucherTransferRejected;
use App\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An admin approves or rejects a gift voucher bank transfer (milestone
 * 10e, admins only, as for orders). Approve records the verifier and time
 * and issues the voucher through IssueVoucher; approving twice changes
 * nothing. Reject needs a reason, closes the purchase and emails the
 * customer.
 */
final class ReviewVoucherTransfer
{
    public function __construct(
        private readonly IssueVoucher $issue,
        private readonly VoucherOrderStateMachine $orders,
        private readonly Notifier $notifier,
    ) {}

    /** @throws VoucherRejected */
    public function approve(VoucherOrder $order, User $admin): Voucher
    {
        self::assertAdmin($admin);

        return DB::transaction(function () use ($order, $admin): Voucher {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === VoucherOrderStatus::Paid) {
                return $this->issue->handle($locked, $admin);
            }

            if ($locked->status !== VoucherOrderStatus::AwaitingVerification) {
                throw VoucherRejected::transferNotOpen();
            }

            $locked->verified_by = $admin->id;
            $locked->verified_at = CarbonImmutable::now();
            $meta = $locked->meta ?? [];
            $meta['payments'] = [...(is_array($meta['payments'] ?? null) ? $meta['payments'] : []), [
                'provider' => 'bank_transfer',
                'provider_id' => $locked->transaction_ref,
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'status' => 'succeeded',
                'at' => CarbonImmutable::now()->toIso8601String(),
            ]];
            $locked->meta = $meta;
            $locked->save();

            activity('vouchers')
                ->performedOn($locked)
                ->causedBy($admin)
                ->event('transfer_approved')
                ->log('Transfer approved for voucher purchase '.$locked->reference);

            $voucher = $this->issue->handle($locked, $admin, ['verified_by' => $admin->id]);
            $order->setRawAttributes($locked->fresh()?->getAttributes() ?? [], true);

            return $voucher;
        });
    }

    /** @throws VoucherRejected */
    public function reject(VoucherOrder $order, User $admin, string $reason): VoucherOrder
    {
        self::assertAdmin($admin);
        $reason = (string) OrderStateMachine::cleanReason($reason);

        if ($reason === '') {
            throw VoucherRejected::notUsable('Give a reason for rejecting the transfer.');
        }

        DB::transaction(function () use ($order, $admin, $reason): void {
            $locked = VoucherOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== VoucherOrderStatus::AwaitingVerification) {
                throw VoucherRejected::transferNotOpen();
            }

            $locked->verified_by = $admin->id;
            $locked->verified_at = CarbonImmutable::now();
            $locked->rejection_reason = $reason;
            $locked->save();

            $this->orders->transition($locked, VoucherOrderStatus::Rejected, $admin, $reason);
            $order->setRawAttributes($locked->getAttributes(), true);
        });

        $order->loadMissing('user');
        $this->notifier->send($order->user, new VoucherTransferRejected($order, $reason), Notifier::key(VoucherTransferRejected::type(), $order, $order->user));

        return $order;
    }

    /** @throws VoucherRejected */
    private static function assertAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw VoucherRejected::notUsable('Only admins can review transfers.');
        }
    }
}
