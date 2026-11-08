<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Credit\AccountCredit;
use App\Actions\Vouchers\VoucherBalance;
use App\Enums\AccountCreditReason;
use App\Enums\VoucherLedgerReason;
use App\Models\AccountCreditEntry;
use App\Models\Order;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * Gives back what an order holds from gift vouchers and account credit
 * (milestone 10e Part B). Used when an unpaid order ends (expired,
 * cancelled, rejected: OrderStateMachine), when checkout recalculates
 * discounts, and for refunds (RestoreOrderDiscounts).
 *
 * A voucher that can't take its share back any more (cancelled, or past
 * its expiry date) gets nothing; the customer receives that share as
 * account credit instead ("voucher_returned"), so nobody loses money that
 * was paid for.
 */
final class ReleaseOrderDiscounts
{
    public function __construct(
        private readonly VoucherBalance $vouchers,
        private readonly AccountCredit $credit,
    ) {}

    /** An unpaid order ended: every hold goes back and the promo use is freed. The order's discount lines stay for the record. */
    public function handle(Order $order, ?User $actor, string $note): void
    {
        DB::transaction(function () use ($order, $actor, $note): void {
            $this->giveBackVouchers($order, null, $actor, $note);
            $this->credit->giveBack($order->user, $order, AccountCredit::heldBy($order), $actor, $note);
            PromoRedemption::query()->where('order_id', $order->id)->delete();
        });
    }

    /**
     * Gives back up to $limit (null: all) of what the order holds on gift
     * vouchers, newest voucher first. Returns what was given back
     * (to the voucher or, failing that, as credit).
     */
    public function giveBackVouchers(Order $order, ?int $limit, ?User $actor, string $note): int
    {
        $given = 0;

        foreach (self::voucherIds($order) as $voucherId) {
            $outstanding = self::voucherOutstanding($order, $voucherId);
            $amount = $limit === null ? $outstanding : min($outstanding, $limit - $given);

            if ($amount <= 0) {
                continue;
            }

            $voucher = Voucher::query()->findOrFail($voucherId);

            if (! $this->vouchers->restore($voucher, $order, $amount, $actor, $note)) {
                $this->credit->giveBack($order->user, $order, $amount, $actor, $note.': gift voucher '.$voucher->formattedCode().' could not take it back', AccountCreditReason::VoucherReturned);
                $this->markMovedToCredit($order, $voucherId, $amount);
            }

            $given += $amount;

            if ($limit !== null && $given >= $limit) {
                break;
            }
        }

        return $given;
    }

    /** Gift voucher money the order still holds and hasn't given back (to the voucher or as credit). */
    public static function voucherOutstanding(Order $order, ?int $voucherId = null): int
    {
        $moved = (array) ($order->fresh()?->meta['voucher_to_credit'] ?? []);
        $movedTotal = $voucherId === null
            ? array_sum(array_map('intval', $moved))
            : (int) ($moved[(string) $voucherId] ?? 0);

        return max(0, VoucherBalance::heldBy($order, $voucherId) - $movedTotal);
    }

    /**
     * Vouchers this order took money from, newest first.
     *
     * @return list<int>
     */
    public static function voucherIds(Order $order): array
    {
        return array_values(array_map('intval', VoucherLedgerEntry::query()
            ->where('order_id', $order->id)
            ->where('reason', VoucherLedgerReason::Redeemed)
            ->orderByDesc('id')
            ->pluck('voucher_id')
            ->unique()
            ->all()));
    }

    /** Remembers voucher money that went back as account credit, so it is never given back twice. */
    private function markMovedToCredit(Order $order, int $voucherId, int $amount): void
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $meta = $locked->meta ?? [];
        $moved = (array) ($meta['voucher_to_credit'] ?? []);
        $moved[(string) $voucherId] = (int) ($moved[(string) $voucherId] ?? 0) + $amount;
        $meta['voucher_to_credit'] = $moved;
        $locked->meta = $meta;
        $locked->save();
        $order->setRawAttributes($locked->getAttributes(), true);
    }

    /** Account credit returned to the customer for vouchers of this order that couldn't take it back. */
    public static function voucherCreditFor(Order $order): int
    {
        return (int) AccountCreditEntry::query()->where('order_id', $order->id)->where('reason', AccountCreditReason::VoucherReturned)->sum('delta_minor');
    }
}
