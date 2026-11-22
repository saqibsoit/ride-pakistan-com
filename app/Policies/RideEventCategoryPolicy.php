<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RideEventCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Event categories (decided 2026-11-22): admins only; one in use is switched off, not deleted. */
final class RideEventCategoryPolicy extends AdminContentPolicy
{
    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && $model instanceof RideEventCategory
            && ! $model->events()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
