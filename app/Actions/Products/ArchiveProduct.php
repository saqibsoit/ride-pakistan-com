<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes a product off sale for good (milestone 10a). Used instead of
 * deleting once a product has orders: the orders, credit packs and
 * receipts keep pointing at it (and show the name as bought). Archived
 * products are hidden from the public site, the booking flow and the
 * admin list's default view. Saving clears the page cache
 * (PublicContentObserver).
 */
final class ArchiveProduct
{
    public function handle(Product $product, ?User $actor = null): Product
    {
        if ($product->isArchived()) {
            return $product;
        }

        return DB::transaction(function () use ($product, $actor): Product {
            $product->archived_at = CarbonImmutable::now();
            $product->is_active = false;
            $product->is_featured = false;
            $product->save();

            activity('content')
                ->performedOn($product)
                ->causedBy($actor)
                ->event('archived')
                ->withProperties(['slug' => $product->slug, 'has_orders' => $product->hasOrders()])
                ->log('Product archived: '.$product->name);

            return $product;
        });
    }
}
