<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: we have their private session request and when we'll
 * answer by (prompt 15B).
 */
final class PrivateRequestReceived extends PrivateRequestNotification
{
    public static function type(): string
    {
        return 'private_request_received';
    }

    public static function label(): string
    {
        return 'Private request received';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('We’ve got your private session request ('.$request->reference.')')
            ->markdown('mail.private-request-received', ['request' => $request, 'account' => route('account.private-requests')]);
    }
}
