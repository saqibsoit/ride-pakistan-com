<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Credit\AccountCredit;
use App\Enums\DiscountKind;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Refunds of a paid order that gift voucher or account credit helped pay
 * for (milestone 10e Part B): the same share of those comes back as of the
 * money. Refunding half the card amount puts back half the voucher and
 * half the credit; the last refund puts back whatever is left. Worked out
 * on the running totals, so rounding never drifts and nothing comes back
 * twice. A voucher that can't take its share back (cancelled or expired)
 * gives it as account credit instead (ReleaseOrderDiscounts).
 *
 * Promo and referral discounts are not money: they stay used.
 */
final class RestoreOrderDiscounts
{
    public function __construct(
        private readonly ReleaseOrderDiscounts $release,
        private readonly AccountCredit $credit,
    ) {}

    /**
     * After a card refund has been recorded on the order.
     *
     * @return array{voucher: int, credit: int} what came back now
     */
    public function afterRefund(Order $order, ?User $actor, string $reason): array
    {
        $order->refresh();

        if ($order->paid_at === null) {
            return ['voucher' => 0, 'credit' => 0]; // never paid: its holds went back when it ended
        }

        $charged = $order->amount_minor;
        $full = $order->amount_refunded_minor >= $charged;

        return $this->restore($order, fn (int $share): int => $full || $charged <= 0
            ? $share
            : intdiv($share * min($order->amount_refunded_minor, $charged), $charged), $actor, $reason);
    }

    /**
     * Everything back (an order paid entirely by voucher and credit, cancelled by an admin).
     *
     * @return array{voucher: int, credit: int}
     */
    public function all(Order $order, ?User $actor, string $reason): array
    {
        return $this->restore($order->refresh(), fn (int $share): int => $share, $actor, $reason);
    }

    /**
     * @param  callable(int): int  $target  how much of a share should have come back by now
     * @return array{voucher: int, credit: int}
     */
    private function restore(Order $order, callable $target, ?User $actor, string $reason): array
    {
        return DB::transaction(function () use ($order, $target, $actor, $reason): array {
            $lines = $order->discounts()->get()->keyBy(fn ($l): string => $l->kind->value);
            $note = 'Refund: '.$reason;

            $voucherShare = (int) ($lines->get(DiscountKind::Voucher->value)->amount_minor ?? 0);
            $voucherBack = $voucherShare - ReleaseOrderDiscounts::voucherOutstanding($order);
            $voucherNow = max(0, $target($voucherShare) - $voucherBack);

            if ($voucherNow > 0) {
                $this->release->giveBackVouchers($order, $voucherNow, $actor, $note);
            }

            $creditShare = (int) ($lines->get(DiscountKind::AccountCredit->value)->amount_minor ?? 0);
            $creditBack = $creditShare - AccountCredit::heldBy($order);
            $creditNow = max(0, $target($creditShare) - $creditBack);

            $this->credit->giveBack($order->user, $order, $creditNow, $actor, $note);

            if ($voucherNow > 0 || $creditNow > 0) {
                activity('discounts')
                    ->performedOn($order)
                    ->causedBy($actor)
                    ->event('restored')
                    ->withProperties(['voucher_minor' => $voucherNow, 'credit_minor' => $creditNow, 'reason' => $reason])
                    ->log('Order '.$order->reference.': gift voucher and credit share returned');
            }

            return ['voucher' => $voucherNow, 'credit' => $creditNow];
        });
    }
}
