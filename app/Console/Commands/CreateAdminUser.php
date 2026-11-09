<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a staff or admin account (or updates an existing one). The
 * password given is temporary: on first sign-in to the panel they must
 * replace it, then set up multi-factor authentication.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Create a staff, trainer or admin user for the admin panel';

    public function handle(): int
    {
        $name = text('Name', required: true, validate: ['name' => ['required', 'string', 'max:255']]);
        $email = text('Email', required: true, validate: ['email' => ['required', 'email:rfc', 'max:190']]);
        $role = UserRole::from((string) select('Role', [
            UserRole::Admin->value => 'Admin (everything)',
            UserRole::Staff->value => 'Staff (slots and bookings, read only)',
            UserRole::Trainer->value => 'Trainer (own sessions and run sheets only)',
        ], default: UserRole::Admin->value));

        $existing = User::query()->where('email', $email)->first();

        $plain = password(
            label: $existing === null ? 'Password' : 'New password (the account already exists)',
            required: true,
            validate: fn (string $value): ?string => Validator::make(
                ['password' => $value],
                ['password' => ['required', Password::defaults()]],
            )->errors()->first('password') ?: null,
        );

        $user = DB::transaction(function () use ($existing, $name, $email, $role, $plain): User {
            $user = $existing ?? new User;
            $previous = $existing?->role;

            $user->fill(['name' => $name, 'email' => $email, 'password' => $plain]);
            $user->role = $role;
            // The password given here is temporary: the panel makes them
            // replace it on first sign-in (EnsurePasswordIsChanged).
            $user->must_change_password = true;
            $user->save();

            activity('users')
                ->performedOn($user)
                ->event($existing === null ? 'created' : 'role_changed')
                ->withProperties(['from' => $previous?->value, 'to' => $role->value, 'via' => 'admin:create'])
                ->log($existing === null ? 'Team member created' : 'Role changed');

            return $user;
        });

        $this->components->info(sprintf(
            '%s %s (%s). On first sign-in they choose their own password, then set up an authenticator app.',
            $existing === null ? 'Created' : 'Updated',
            $user->email,
            $role->label(),
        ));

        return self::SUCCESS;
    }
}
