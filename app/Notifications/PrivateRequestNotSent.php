<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the account holder: a private session request was sent with their
 * email without signing in, but they already have one waiting, so it
 * wasn't sent (guest checkout). The guest who sent it saw the usual
 * "request sent" answer, so this tells nothing to anyone else.
 */
final class PrivateRequestNotSent extends CustomerNotification
{
    public function __construct(public readonly CarbonImmutable $startsAt)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'private_request_not_sent';
    }

    public static function label(): string
    {
        return 'Private request not sent (one already open)';
    }

    public function subject(): ?Model
    {
        return null;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your private session request wasn’t sent')
            ->markdown('mail.private-request-not-sent', [
                'when' => $this->startsAt->setTimezone((string) config('app.display_timezone'))->format('D j M Y, H:i'),
            ]);
    }
}
