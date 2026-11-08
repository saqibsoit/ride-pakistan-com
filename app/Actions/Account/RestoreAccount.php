<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\SettingKey;
use App\Exceptions\AccountActionBlocked;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a deletion request inside the grace period (the owner signed in
 * again and chose "Keep my account"). Logged.
 */
final class RestoreAccount
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @throws AccountActionBlocked
     */
    public function handle(User $user): void
    {
        $graceDays = $this->settings->int(SettingKey::DeletionGraceDays) ?? 30;

        if (! $user->trashed()) {
            return;
        }

        if ($user->isAnonymised() || ! $user->withinDeletionGracePeriod($graceDays)) {
            throw new AccountActionBlocked(['This account can no longer be restored.']);
        }

        DB::transaction(function () use ($user): void {
            $user->restore();

            activity('account')
                ->performedOn($user)
                ->causedBy($user)
                ->event('restored')
                ->log('Account deletion cancelled by the customer');
        });
    }
}
