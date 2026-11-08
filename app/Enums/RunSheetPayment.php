<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Booking;

/**
 * How a booking was paid, as the run sheet shows it (milestone 10b). No
 * amounts: trainers don't see payments. "Needs review" means an admin has
 * to look at the order (flagged, refunded or otherwise not plainly paid).
 */
enum RunSheetPayment: string
{
    case Paid = 'paid';
    case Credit = 'credit';
    case NeedsReview = 'needs_review';
    case NoCharge = 'no_charge';

    public static function for(Booking $booking): self
    {
        $order = $booking->order ?? $booking->creditPack?->order;

        if ($order !== null && (isset($order->meta['needs_review']) || $order->status !== OrderStatus::Paid)) {
            return self::NeedsReview;
        }

        if ($booking->credit_pack_id !== null) {
            return self::Credit;
        }

        return $booking->order_id !== null ? self::Paid : self::NoCharge;
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Credit => 'Credit',
            self::NeedsReview => 'Needs review',
            self::NoCharge => 'No charge',
        };
    }

    /** x-badge variant; the label always carries the meaning too. */
    public function badge(): string
    {
        return match ($this) {
            self::Paid, self::Credit => 'success',
            self::NeedsReview => 'warning',
            self::NoCharge => 'neutral',
        };
    }
}
