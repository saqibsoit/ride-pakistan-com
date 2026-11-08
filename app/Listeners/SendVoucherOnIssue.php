<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Vouchers\DeliverVoucher;
use App\Events\VoucherIssued;
use App\Notifications\VoucherPurchaseConfirmed;
use App\Support\Notifier;

/**
 * Once a voucher exists (after commit): the buyer gets their receipt, and
 * a voucher to be sent straight away goes to the recipient. Scheduled
 * vouchers wait for vouchers:deliver.
 */
final class SendVoucherOnIssue
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly DeliverVoucher $deliver,
    ) {}

    public function handle(VoucherIssued $event): void
    {
        $voucher = $event->voucher;
        $order = $voucher->voucherOrder()->with('user')->first();

        if ($order !== null) {
            $this->notifier->send($order->user, new VoucherPurchaseConfirmed($order, $voucher), Notifier::key(VoucherPurchaseConfirmed::type(), $order, $order->user));
        }

        $this->deliver->handle($voucher);
    }
}
