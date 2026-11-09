<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * A team member replaces the temporary password admin:create gave them
 * (PLAN §7). The new one must differ from the temporary one; the password
 * rules themselves are checked by the form (Password::defaults()). The
 * change is audited by User::booted() ("password_changed"), never the value.
 */
final class ReplaceTemporaryPassword
{
    /**
     * @throws ValidationException when the new password is the temporary one
     */
    public function handle(User $user, #[SensitiveParameter] string $newPassword): void
    {
        if (Hash::check($newPassword, (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Choose a new password, not the temporary one.',
            ]);
        }

        DB::transaction(function () use ($user, $newPassword): void {
            $user->password = $newPassword;
            $user->must_change_password = false;
            $user->save();
        });
    }
}
