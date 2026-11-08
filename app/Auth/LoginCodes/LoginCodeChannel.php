<?php

declare(strict_types=1);

namespace App\Auth\LoginCodes;

use App\Enums\LoginCodeChannelType;
use Carbon\CarbonImmutable;

/**
 * A way of delivering one-time sign-in codes. Email today; a phone or
 * WhatsApp channel adds another implementation without touching the
 * request and verify actions.
 */
interface LoginCodeChannel
{
    public function type(): LoginCodeChannelType;

    /** Canonical form of the destination (lower-cased email, E.164 phone). */
    public function normalise(string $destination): string;

    public function send(string $destination, string $code, CarbonImmutable $expiresAt): void;
}
