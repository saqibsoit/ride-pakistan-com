<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\LeadCaptured;
use App\Models\User;
use App\Notifications\NewLeadReceived;
use App\Support\Notifier;

/**
 * Emails every admin about each new site lead (contact form, /prices
 * question). LeadCaptured fires only for site forms, so leads staff enter
 * by hand don't alert. One email per lead per admin (dedupe key); the
 * notification itself is queued.
 */
final class NotifyAdminsOfLead
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(LeadCaptured $event): void
    {
        $lead = $event->lead;

        User::query()
            ->where('role', UserRole::Admin)
            ->whereNotNull('email')
            ->each(fn (User $admin) => $this->notifier->send(
                $admin,
                new NewLeadReceived($lead),
                Notifier::key(NewLeadReceived::type(), $lead, $admin),
            ));
    }
}
