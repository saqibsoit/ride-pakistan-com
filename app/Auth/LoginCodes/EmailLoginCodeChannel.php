<?php

declare(strict_types=1);

namespace App\Auth\LoginCodes;

use App\Enums\LoginCodeChannelType;
use App\Mail\LoginCodeMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sends the code by email straight away (not queued: the cron-driven queue
 * could delay a sign-in by up to a minute).
 */
final class EmailLoginCodeChannel implements LoginCodeChannel
{
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
        Mail::to($destination)->send(new LoginCodeMail($code, $expiresAt));
    }
}
