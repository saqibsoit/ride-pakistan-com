<?php

declare(strict_types=1);

namespace App\Actions\Discounts;

use App\Actions\Leads\LeadReport;
use App\Enums\DiscountType;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Can this promo code be used on this order (milestone 10e Part B)? Gives
 * the reason it can't, in words for the customer, or null. Uses are
 * counted from promo_redemptions, which hold only open and paid orders
 * (an unpaid order that ends gives its use back), leaving this order's
 * own use out. Call it with the promo row locked when counting matters.
 */
final class PromoEligibility
{
    public function reason(PromoCode $promo, User $customer, Order $order, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $tz = (string) config('app.display_timezone');

        if ($promo->isArchived() || ! $promo->is_active) {
            return 'This code isn’t active.';
        }

        if ($promo->starts_at !== null && $promo->starts_at->isAfter($now)) {
            return 'This code starts on '.$promo->starts_at->setTimezone($tz)->format('j M Y').'.';
        }

        if ($promo->ends_at !== null && ! $promo->ends_at->isAfter($now)) {
            return 'This code ended on '.$promo->ends_at->setTimezone($tz)->format('j M Y').'.';
        }

        $uses = PromoRedemption::query()->where('promo_code_id', $promo->id)->where('order_id', '!=', $order->id);

        if ($promo->max_uses !== null && (clone $uses)->count() >= $promo->max_uses) {
            return 'This code has been used up.';
        }

        if ($promo->max_uses_per_customer !== null && (clone $uses)->where('user_id', $customer->id)->count() >= $promo->max_uses_per_customer) {
            return 'You’ve already used this code.';
        }

        $products = $promo->products()->pluck('products.id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($products !== [] && ! in_array($order->product_id, $products, true)) {
            return 'This code isn’t valid for '.$order->productName().'.';
        }

        if ($promo->min_order_minor !== null && $order->subtotal_minor < $promo->min_order_minor) {
            return 'This code needs an order of at least '.Money::format($promo->min_order_minor).'.';
        }

        if ($promo->first_booking_only && self::hasPaidBefore($customer, $order)) {
            return 'This code is for your first booking only.';
        }

        return null;
    }

    /** The discount a promo gives on a subtotal: a percentage rounded down to whole rupees, or a fixed amount, never more than the subtotal. */
    public static function amountFor(PromoCode $promo, int $subtotalMinor): int
    {
        $amount = $promo->discount_type === DiscountType::Percent
            ? intdiv($subtotalMinor * $promo->percent, 100 * Money::MINOR_PER_MAJOR) * Money::MINOR_PER_MAJOR
            : (int) $promo->amount_minor;

        return max(0, min($amount, $subtotalMinor));
    }

    /** Any other order of this customer was paid (refunded ones count: the first booking happened). */
    public static function hasPaidBefore(User $customer, Order $order): bool
    {
        return Order::query()
            ->where('user_id', $customer->id)
            ->whereKeyNot($order->id)
            ->whereIn('status', LeadReport::PAID_STATUSES)
            ->exists();
    }
}
