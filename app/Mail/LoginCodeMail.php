<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent straight away by EmailLoginCodeChannel. Only when that fails is it
 * queued for a retry (milestone 12): encrypted on the queue, because it
 * carries the code, and with short retries, because the code expires after
 * login.code.ttl_minutes.
 */
final class LoginCodeMail extends Mailable implements ShouldBeEncrypted
{
    public int $tries = 3;

    public function __construct(
        public readonly string $code,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Ride Pakistan sign-in code: '.$this->code);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.login-code',
            with: [
                'minutes' => (int) config('login.code.ttl_minutes'),
            ],
        );
    }
}
