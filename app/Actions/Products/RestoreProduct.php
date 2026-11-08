<?php

declare(strict_types=1);

namespace App\Actions\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived product back into the admin list (milestone 10a).
 * It stays off sale until an admin switches "On sale" on again, so
 * nothing reappears on the site by accident.
 */
final class RestoreProduct
{
    public function handle(Product $product, ?User $actor = null): Product
    {
        if (! $product->isArchived()) {
            return $product;
        }

        return DB::transaction(function () use ($product, $actor): Product {
            $product->archived_at = null;
            $product->is_active = false;
            $product->save();

            activity('content')
                ->performedOn($product)
                ->causedBy($actor)
                ->event('restored')
                ->withProperties(['slug' => $product->slug])
                ->log('Product restored from the archive (off sale): '.$product->name);

            return $product;
        });
    }
}
