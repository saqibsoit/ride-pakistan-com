<?php

declare(strict_types=1);

namespace App\Support\Events;

/**
 * Event money rules (decided 2026-11-22), in paisa.
 *
 * - The deposit is a percentage of the price after discounts, rounded up
 *   to whole rupees and never more than the price. The rest is the
 *   balance.
 * - Deposits are non-refundable. A customer who cancels before the
 *   event's cutoff gets back what they paid minus the event's
 *   non-refundable share of the price (that share defaults to the deposit
 *   percentage, so the deposit is kept and a paid balance comes back; a
 *   full-payment event with 0 % refunds everything). After the cutoff
 *   nothing comes back.
 * - When we cancel, everything paid comes back.
 */
final class EventMoney
{
    /** $percent of $amountMinor, rounded up to whole rupees, never more than $amountMinor. */
    public static function share(int $amountMinor, int $percent): int
    {
        if ($amountMinor <= 0 || $percent <= 0) {
            return 0;
        }

        if ($percent >= 100) {
            return $amountMinor;
        }

        $rupees = intdiv($amountMinor * $percent + 9999, 10000);

        return min($amountMinor, $rupees * 100);
    }

    /**
     * Split a price after discounts into what is charged now and the balance.
     *
     * @return array{now: int, balance: int}
     */
    public static function split(int $netMinor, ?int $depositPercent): array
    {
        $netMinor = max(0, $netMinor);

        if ($depositPercent === null) {
            return ['now' => $netMinor, 'balance' => 0];
        }

        $now = self::share($netMinor, $depositPercent);

        return ['now' => $now, 'balance' => $netMinor - $now];
    }

    /**
     * What a customer cancellation refunds.
     *
     * @param  int  $paidMinor  money received for this order (deposit or full payment plus any balance), net of refunds
     * @param  int  $priceMinor  the order's price after discounts (charged now + balance)
     */
    public static function customerRefund(int $paidMinor, int $priceMinor, int $nonRefundablePercent, bool $beforeCutoff): int
    {
        if (! $beforeCutoff) {
            return 0;
        }

        return max(0, $paidMinor - self::share($priceMinor, $nonRefundablePercent));
    }
}
