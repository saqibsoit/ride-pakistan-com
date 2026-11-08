<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/**
 * Daily (accounts:anonymise). A deleted account is anonymised once the
 * grace period has passed and its newest paid order is older than the
 * retention period (PLAN §7). Accounts still inside retention stay
 * soft-deleted and locked and are checked again the next day.
 */
final class AnonymiseDeletedAccounts
{
    public function __construct(
        private readonly AnonymiseAccount $anonymise,
        private readonly Settings $settings,
    ) {}

    /** @return int accounts anonymised */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $graceEnded = $now->subDays($this->settings->int(SettingKey::DeletionGraceDays) ?? 30);
        $retentionStart = $now->subYears($this->settings->int(SettingKey::OrderRetentionYears) ?? 6);

        $count = 0;

        User::onlyTrashed()
            ->whereNull('anonymised_at')
            ->where('deleted_at', '<=', $graceEnded)
            ->whereDoesntHave('orders', fn ($orders) => $orders->where('paid_at', '>', $retentionStart))
            ->orderBy('id')
            ->each(function (User $user) use (&$count): void {
                $this->anonymise->handle($user);
                $count++;
            });

        return $count;
    }
}
