<?php

declare(strict_types=1);

namespace App\Auth\LoginCodes;

use App\Actions\Mail\ReportMailFailure;
use App\Enums\LoginCodeChannelType;
use App\Mail\LoginCodeMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends the code by email straight away (not queued: the cron-driven queue
 * could delay a sign-in by up to a minute). If the provider fails, the same
 * email is queued and retried with backoff (decided 2026-11-08), so a short
 * outage doesn't lose the code; the customer still sees "code sent".
 */
final class EmailLoginCodeChannel implements LoginCodeChannel
{
    public function __construct(private readonly ReportMailFailure $failures) {}

    public function type(): LoginCodeChannelType
    {
        return LoginCodeChannelType::Email;
    }

    public function normalise(string $destination): string
    {
        return Str::lower(trim($destination));
    }

    public function send(string $destination, string $code, CarbonImmutable $expiresAt): void
    {
        try {
            Mail::to($destination)->send(new LoginCodeMail($code, $expiresAt));
        } catch (Throwable $e) {
            try {
                Mail::to($destination)->queue(new LoginCodeMail($code, $expiresAt));
            } catch (Throwable $queueError) {
                $this->failures->failedFor(LoginCodeMail::class, $e);

                throw $queueError;
            }

            $this->failures->retrying(LoginCodeMail::class, $e);
        }
    }
}
