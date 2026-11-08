<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Notifications\CustomerNotification;
use Illuminate\Notifications\Events\NotificationSent;

/** Records when the email for a logged notification actually went out. */
final class MarkNotificationSent
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof CustomerNotification || $event->notification->logId === null) {
            return;
        }

        NotificationLog::query()->whereKey($event->notification->logId)->update(['sent_at' => now()]);
    }
}
