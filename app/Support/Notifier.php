<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\CustomerNotification;
use Carbon\CarbonImmutable;

/**
 * Sends a customer notification and logs it in notification_log
 * (milestone 8). With a dedupe key the send is claimed first (unique key),
 * so scheduled reminders and warnings go out exactly once even if the job
 * runs again before the queue has delivered them. Anonymised or missing
 * addresses are skipped.
 */
final class Notifier
{
    /**
     * @return bool whether it was queued
     */
    public function send(User $user, CustomerNotification $notification, ?string $dedupeKey = null, ?User $resentBy = null): bool
    {
        $email = (string) $user->email;

        if ($email === '' || str_ends_with($email, '.invalid')) {
            return false;
        }

        $subject = $notification->subject();
        $now = CarbonImmutable::now();

        $row = [
            'user_id' => $user->id,
            'type' => $notification::type(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'dedupe_key' => $dedupeKey === null ? null : mb_substr($dedupeKey, 0, 191),
            'resent_by' => $resentBy?->id,
            'queued_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($dedupeKey !== null) {
            if (NotificationLog::query()->insertOrIgnore($row) === 0) {
                return false; // already sent (or being sent)
            }

            $logId = (int) NotificationLog::query()->where('dedupe_key', $row['dedupe_key'])->value('id');
        } else {
            $logId = NotificationLog::query()->create($row)->id;
        }

        $notification->logId = $logId;
        $user->notify($notification);

        return true;
    }

    /** Dedupe key for one automatic notification of a type about a record, for a user. */
    public static function key(string $type, ?object $subject, User $user): string
    {
        $id = $subject !== null && method_exists($subject, 'getKey') ? (string) $subject->getKey() : '-';

        return $type.':'.($subject === null ? '-' : class_basename($subject)).':'.$id.':'.$user->id;
    }
}
