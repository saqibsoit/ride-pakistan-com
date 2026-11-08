<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\MailDeliveryFailing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Email delivery failures (milestone 12, Resend). Every failed attempt is
 * logged; a send that fails for good (all retries used, or a sign-in code
 * that couldn't even be queued for a retry) is logged as an error and
 * emails every admin, at most once every ALERT_EVERY_MINUTES.
 *
 * Logs never contain the email itself (subject, body, sign-in code): only
 * the kind of email, the queue job id, the attempt and the error, with
 * email addresses masked.
 */
final class ReportMailFailure
{
    public const ALERT_EVERY_MINUTES = 60;

    public const ALERT_CACHE_KEY = 'mail-failure-alert';

    public static function isMailJob(Job $job): bool
    {
        $command = $job->payload()['data']['commandName'] ?? null;

        return in_array($command, [SendQueuedNotifications::class, SendQueuedMailable::class], true);
    }

    /** A queued attempt failed; the queue will retry it if attempts are left. */
    public function attemptFailed(Job $job, Throwable $e): void
    {
        Log::warning('Email delivery attempt failed', $this->context($job->resolveName(), $e) + [
            'job_id' => $job->uuid(),
            'attempt' => $job->attempts(),
        ]);
    }

    /** A queued email failed for good. */
    public function failed(Job $job, Throwable $e): void
    {
        $this->failedFor($job->resolveName(), $e, ['job_id' => $job->uuid(), 'attempts' => $job->attempts()]);
    }

    /**
     * A send that wasn't queued failed, and no retry is possible.
     *
     * @param  array<string, mixed>  $extra
     */
    public function failedFor(string $kind, Throwable $e, array $extra = []): void
    {
        $context = $this->context($kind, $e) + $extra;

        Log::error('Email delivery failed', $context);

        $this->alertAdmins($context['email'], $context['error']);
    }

    /** An immediate send failed and was queued for a retry: no alert yet. */
    public function retrying(string $kind, Throwable $e): void
    {
        Log::warning('Email send failed, queued for retry', $this->context($kind, $e));
    }

    /**
     * @return array{email: string, error: string, exception: class-string}
     */
    private function context(string $kind, Throwable $e): array
    {
        return [
            'email' => class_basename($kind),
            'error' => self::safeMessage($e),
            'exception' => $e::class,
        ];
    }

    /** The error without email addresses, shortened. */
    public static function safeMessage(Throwable $e): string
    {
        $message = (string) preg_replace('/[^\s<>"\'()]+@[^\s<>"\'()]+/', '[address]', $e->getMessage());

        return Str::limit(trim($message), 300);
    }

    private function alertAdmins(string $kind, string $error): void
    {
        // Cache::add is atomic: only the first failure in the window alerts.
        if (! Cache::add(self::ALERT_CACHE_KEY, CarbonImmutable::now()->getTimestamp(), now()->addMinutes(self::ALERT_EVERY_MINUTES))) {
            return;
        }

        $alert = new MailDeliveryFailing($kind, $error, CarbonImmutable::now());

        User::query()
            ->where('role', UserRole::Admin)
            ->whereNull('anonymised_at')
            ->get()
            ->reject(fn (User $admin): bool => (string) $admin->email === '' || str_ends_with((string) $admin->email, '.invalid'))
            ->each(function (User $admin) use ($alert): void {
                try {
                    // Straight away: the queue or the provider may be the problem.
                    $admin->notifyNow($alert);
                } catch (Throwable $e) {
                    // Usually the same outage. The log is the only record left.
                    Log::critical('Email failure alert could not be sent', [
                        'admin_id' => $admin->id,
                        'error' => self::safeMessage($e),
                    ]);
                }
            });
    }
}
