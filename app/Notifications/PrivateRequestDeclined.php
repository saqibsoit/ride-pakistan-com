<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: their private session request was declined (prompt 15B),
 * with the reason and any suggested times.
 */
final class PrivateRequestDeclined extends PrivateRequestNotification
{
    public static function type(): string
    {
        return 'private_request_declined';
    }

    public static function label(): string
    {
        return 'Private request declined';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('About your private session request ('.$request->reference.')')
            ->markdown('mail.private-request-declined', ['request' => $request, 'requestUrl' => route('book', ['product' => $request->product->slug])]);
    }
}
