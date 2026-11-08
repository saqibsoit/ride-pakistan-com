<?php

declare(strict_types=1);

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LoginCodeMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly CarbonImmutable $expiresAt,
    ) {}

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
