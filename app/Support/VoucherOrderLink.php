<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\VoucherOrderStatus;
use App\Models\VoucherOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The signed "Your gift voucher purchase" link in the buyer's emails
 * (guest checkout, decided 2026-11-12): pay, upload a transfer and follow
 * the purchase without signing in, for that one purchase. It works while
 * the purchase is unpaid or the voucher hasn't been sent, and until
 * DAYS_AFTER days after it was paid and sent (or closed unpaid). Changing
 * the recipient stays in the account.
 */
final class VoucherOrderLink
{
    public const DAYS_AFTER = 30;

    public static function for(VoucherOrder $order): string
    {
        return URL::signedRoute('gift-vouchers.open', ['reference' => $order->reference, 'v' => $order->manage_link_version]);
    }

    public static function isOpen(VoucherOrder $order, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        if (in_array($order->status, [VoucherOrderStatus::PendingPayment, VoucherOrderStatus::AwaitingTransfer, VoucherOrderStatus::AwaitingVerification], true)) {
            return true;
        }

        $voucher = $order->voucher;

        if ($voucher !== null && $voucher->delivered_at === null) {
            return true; // paid, sending on a later date
        }

        $closed = $voucher->delivered_at ?? $order->paid_at ?? $order->updated_at;

        return $closed === null || $now->lessThan(CarbonImmutable::parse($closed)->addDays(self::DAYS_AFTER));
    }
}
