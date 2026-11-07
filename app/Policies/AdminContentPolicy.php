<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed content: only users allowed into the admin may read or
 * change it there. The public site reads published content without policies.
 */
abstract class AdminContentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('access-admin');
    }

    public function create(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('access-admin');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('access-admin');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('access-admin');
    }

    public function reorder(User $user): bool
    {
        return $user->can('access-admin');
    }
}
