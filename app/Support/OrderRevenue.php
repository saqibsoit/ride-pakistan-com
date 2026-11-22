<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountCreditReason;
use App\Enums\BalanceStatus;
use App\Enums\VoucherLedgerReason;
use App\Models\AccountCreditEntry;
use App\Models\Order;
use App\Models\OrderBalance;
use App\Models\VoucherLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Revenue of paid orders (milestone 10e Part B), one rule for the dashboard,
 * lead reports and exports:
 *
 *   money received (card or transfer) net of refunds
 * + gift voucher balance spent on the order, net of what went back
 *   (to the voucher, or as account credit when it couldn't).
 *
 * Gift vouchers were paid for in money when bought, so they count when
 * they are spent. Promo codes, referral discounts and account credit are
 * discounts: they are not revenue.
 *
 * Event orders (decided 2026-11-22): money received includes balance
 * payments (online or recorded at the venue), net of balance refunds.
 */
final class OrderRevenue
{
    public static function of(Order $order): int
    {
        return self::cash($order) + (self::voucherByOrder([$order->id])[$order->id] ?? 0);
    }

    public static function cash(Order $order): int
    {
        $cash = max(0, $order->amount_minor - $order->amount_refunded_minor);

        // Only event orders have a balance; no query for lesson orders.
        if (! $order->isEvent()) {
            return $cash;
        }

        $balance = $order->relationLoaded('balance') ? $order->balance : $order->balance()->first();

        return $cash + ($balance?->receivedMinor() ?? 0);
    }

    /**
     * Event balance money each order kept (received net of refunds), by order id.
     *
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    public static function balanceByOrder(array $orderIds): array
    {
        $kept = [];

        foreach (array_chunk($orderIds, 1000) as $chunk) {
            $rows = OrderBalance::query()
                ->whereIn('order_id', $chunk)
                ->whereIn('status', [BalanceStatus::Paid, BalanceStatus::Refunded])
                ->get(['order_id', 'amount_minor', 'refunded_minor', 'status']);

            foreach ($rows as $row) {
                $kept[$row->order_id] = $row->receivedMinor();
            }
        }

        return array_filter($kept);
    }

    /**
     * Total revenue of the orders a query selects.
     *
     * @param  Builder<Order>  $orders
     */
    public static function total(Builder $orders): int
    {
        $cash = (int) (clone $orders)->sum(DB::raw('greatest(amount_minor - amount_refunded_minor, 0)'));
        $ids = array_values((clone $orders)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all());

        return $cash + array_sum(self::balanceByOrder($ids)) + array_sum(self::voucherByOrder($ids));
    }

    /**
     * Gift voucher money each order kept, by order id (orders without any are left out).
     *
     * @param  list<int>  $orderIds
     * @return array<int, int>
     */
    public static function voucherByOrder(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $spent = [];

        foreach (array_chunk($orderIds, 1000) as $chunk) {
            $rows = VoucherLedgerEntry::query()
                ->whereIn('order_id', $chunk)
                ->whereIn('reason', [VoucherLedgerReason::Redeemed, VoucherLedgerReason::Restored])
                ->groupBy('order_id')
                ->selectRaw('order_id, -sum(delta_minor) as spent')
                ->pluck('spent', 'order_id');

            foreach ($rows as $orderId => $amount) {
                $spent[(int) $orderId] = (int) $amount;
            }

            $returned = AccountCreditEntry::query()
                ->whereIn('order_id', $chunk)
                ->where('reason', AccountCreditReason::VoucherReturned)
                ->groupBy('order_id')
                ->selectRaw('order_id, sum(delta_minor) as returned')
                ->pluck('returned', 'order_id');

            foreach ($returned as $orderId => $amount) {
                $spent[(int) $orderId] = ($spent[(int) $orderId] ?? 0) - (int) $amount;
            }
        }

        return array_filter(array_map(fn (int $v): int => max(0, $v), $spent));
    }
}
