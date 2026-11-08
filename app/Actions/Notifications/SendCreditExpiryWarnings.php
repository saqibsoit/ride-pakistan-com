<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\CreditPackStatus;
use App\Models\CreditPack;
use App\Notifications\CreditsExpiringSoon;
use App\Support\Notifier;
use Carbon\CarbonImmutable;

/**
 * credits:remind (daily). Active packs with unused credits that expire
 * within notifications.credits_expiring_days [7] get one warning per pack.
 */
final class SendCreditExpiryWarnings
{
    public function __construct(private readonly Notifier $notifier) {}

    /** @return int warnings queued */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $days = (int) config('notifications.credits_expiring_days', 7);
        $sent = 0;

        CreditPack::query()
            ->where('status', CreditPackStatus::Active)
            ->whereColumn('credits_used', '<', 'credits_total')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->addDays($days))
            ->with('user')
            ->orderBy('id')
            ->each(function (CreditPack $pack) use (&$sent): void {
                if ($this->notifier->send($pack->user, new CreditsExpiringSoon($pack), Notifier::key(CreditsExpiringSoon::type(), $pack, $pack->user))) {
                    $sent++;
                }
            });

        return $sent;
    }
}
