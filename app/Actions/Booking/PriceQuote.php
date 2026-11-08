<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\ProductType;
use App\Models\Product;

/**
 * What an order costs, from the product row only (PLAN §2): single and
 * private sessions per rider, a package once (its first-session seats are
 * paid by its own credits). Used by CreateOrder and the booking review, so
 * the price shown is the price charged.
 */
final class PriceQuote
{
    public function __construct(
        public readonly int $unitMinor,
        public readonly int $quantity,
        public readonly int $totalMinor,
        public readonly bool $perRider,
    ) {}

    public static function for(Product $product, int $riders): self
    {
        if ($product->type === ProductType::Package) {
            return new self($product->price_minor, 1, $product->price_minor, false);
        }

        $riders = max(1, $riders);

        return new self($product->price_minor, $riders, $product->price_minor * $riders, true);
    }
}
