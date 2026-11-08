<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AccountCreditReason;
use App\Enums\VoucherLedgerReason;
use App\Models\AccountCreditEntry;
use App\Models\Order;
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
 */
final class OrderRevenue
{
    public static function of(Order $order): int
    {
        return self::cash($order) + (self::voucherByOrder([$order->id])[$order->id] ?? 0);
    }

    public static function cash(Order $order): int
    {
        return max(0, $order->amount_minor - $order->amount_refunded_minor);
    }

    /**
     * Total revenue of the orders a query selects.
     *
     * @param  Builder<Order>  $orders
     */
    public static function total(Builder $orders): int
    {
        $cash = (int) (clone $orders)->sum(DB::raw('greatest(amount_minor - amount_refunded_minor, 0)'));
        $ids = (clone $orders)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return $cash + array_sum(self::voucherByOrder(array_values($ids)));
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
