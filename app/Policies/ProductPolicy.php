<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Products (milestone 10a): admins create, edit, reorder, archive and
 * restore. Only a product that was never ordered can be deleted; one with
 * orders is archived instead (orders point at it). An archived product
 * is read-only until it is restored.
 */
final class ProductPolicy extends AdminContentPolicy
{
    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model)
            && $model instanceof Product
            && ! $model->isArchived();
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && $model instanceof Product
            && ! $model->hasOrders();
    }

    /** Bulk delete is off: each product is checked for orders one by one. */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function archive(User $user, Product $product): bool
    {
        return $user->can('access-admin') && ! $product->isArchived();
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->can('access-admin') && $product->isArchived();
    }
}
