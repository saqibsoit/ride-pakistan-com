<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ProductPolicy extends AdminContentPolicy
{
    /** Sold products stay (orders point at them); switch them off instead. */
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && $model instanceof Product
            && ! $model->orders()->exists();
    }
}
