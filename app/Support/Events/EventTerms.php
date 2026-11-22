<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Models\Order;
use App\Models\RideEvent;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * The cancellation rules of an event in plain words (decided 2026-11-22),
 * shown on the event page, next to the payment buttons, in the
 * confirmation email and on the manage page. One source, so they always
 * say the same. An order uses the terms it was booked on.
 */
final class EventTerms
{
    /**
     * @return list<string>
     */
    public static function lines(RideEvent $event, ?Order $order = null): array
    {
        $deposit = $order !== null ? $order->deposit_percent : $event->depositPercent();
        $kept = (int) ($order->meta['non_refundable_percent'] ?? $event->non_refundable_percent);
        $days = (int) ($order->meta['cancellation_cutoff_days'] ?? $event->cancellation_cutoff_days);
        $daysText = $days.' '.Str::plural('day', $days);
        $lines = [];

        if ($deposit !== null) {
            $lines[] = 'The '.$deposit.'% deposit confirms your place and is non-refundable.';
        }

        if ($kept >= 100) {
            $lines[] = 'If you cancel, there is no refund.';
        } elseif ($kept <= 0) {
            $lines[] = 'Cancel at least '.$daysText.' before the date for a full refund of what you paid.';
        } elseif ($deposit !== null && $kept === $deposit) {
            $lines[] = 'Cancel at least '.$daysText.' before the date and we refund any balance you have paid; the deposit is kept.';
        } else {
            $lines[] = 'Cancel at least '.$daysText.' before the date and we refund what you paid above '.$kept.'% of the price.';
        }

        if ($kept < 100) {
            $lines[] = 'Within '.$daysText.' of the date there is no refund.';
        }

        $lines[] = 'If we cancel a date, you get a full refund (deposit included) or can move to another date.';

        return $lines;
    }

    /** "PKR 5,000 deposit now, PKR 5,000 on the day" for an order. */
    public static function paymentSummary(Order $order): string
    {
        if ($order->deposit_percent === null || $order->balance_minor <= 0) {
            return Money::format($order->amount_minor, $order->currency).' now';
        }

        return Money::format($order->amount_minor, $order->currency).' deposit now, '.Money::format($order->balance_minor, $order->currency).' balance later';
    }
}
