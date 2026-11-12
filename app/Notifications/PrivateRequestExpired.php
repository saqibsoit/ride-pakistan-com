<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: nobody answered their private session request in time
 * (prompt 15B), so it has expired.
 */
final class PrivateRequestExpired extends PrivateRequestNotification
{
    public static function type(): string
    {
        return 'private_request_expired';
    }

    public static function label(): string
    {
        return 'Private request expired (no answer)';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('Your private session request has expired ('.$request->reference.')')
            ->markdown('mail.private-request-expired', ['request' => $request, 'requestUrl' => route('book', ['product' => $request->product->slug]), 'contactUrl' => route('contact')]);
    }
}
