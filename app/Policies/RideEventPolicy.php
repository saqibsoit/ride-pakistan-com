<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RideEventStatus;
use App\Models\RideEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Events (decided 2026-11-22): admins build, publish, duplicate and archive
 * them. An event with bookings is archived, never deleted; an archived
 * event is read-only until it is restored to draft.
 */
final class RideEventPolicy extends AdminContentPolicy
{
    public function update(User $user, Model $model): bool
    {
        return parent::update($user, $model)
            && $model instanceof RideEvent
            && $model->status !== RideEventStatus::Archived;
    }

    public function delete(User $user, Model $model): bool
    {
        return parent::delete($user, $model)
            && $model instanceof RideEvent
            && ! $model->hasOrders();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function duplicate(User $user, RideEvent $event): bool
    {
        return $user->can('access-admin');
    }

    public function changeStatus(User $user, RideEvent $event): bool
    {
        return $user->can('access-admin');
    }
}
