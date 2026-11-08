<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Deletes a product that was never ordered (milestone 10a). A product
 * with orders can only be archived (ArchiveProduct). FAQs linked to it
 * become general FAQs (nullOnDelete). The delete is audited by
 * LogsContentActivity and clears the page cache (PublicContentObserver).
 */
final class DeleteProduct
{
    public function handle(Product $product, ?User $actor = null): void
    {
        DB::transaction(function () use ($product, $actor): void {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($locked->hasOrders()) {
                throw new LogicException('This product has orders, so it can only be archived.');
            }

            activity('content')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('deleted_product')
                ->withProperties(['slug' => $locked->slug, 'name' => $locked->name])
                ->log('Product deleted: '.$locked->name);

            $locked->delete();
        });
    }
}
