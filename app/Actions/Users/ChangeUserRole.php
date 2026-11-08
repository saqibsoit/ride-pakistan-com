<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way a user's role changes from the admin. Admins cannot change
 * their own role (UserPolicy::changeRole). Every change is logged.
 */
final class ChangeUserRole
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, UserRole $role, User $actor): User
    {
        if (! $actor->can('changeRole', $user)) {
            throw new AuthorizationException('You cannot change this user\'s role.');
        }

        $from = $user->role;

        if ($from === $role) {
            return $user;
        }

        DB::transaction(function () use ($user, $role, $from, $actor): void {
            $user->role = $role;
            $user->save();

            activity('users')
                ->performedOn($user)
                ->causedBy($actor)
                ->event('role_changed')
                ->withProperties(['from' => $from->value, 'to' => $role->value])
                ->log(sprintf('Role changed: %s → %s', $from->label(), $role->label()));
        });

        return $user;
    }
}
