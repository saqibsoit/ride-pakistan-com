<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Slot;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Branded test email for `php artisan mail:test` (milestone 12). */
final class TestMail extends Mailable
{
    public function __construct(public readonly CarbonImmutable $sentAt) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ride Pakistan test email');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.test',
            with: [
                'mailer' => (string) config('mail.default'),
                'environment' => app()->environment(),
                'sentAt' => $this->sentAt->setTimezone(Slot::displayTimezone())->format('D j M Y, H:i:s'),
            ],
        );
    }
}
