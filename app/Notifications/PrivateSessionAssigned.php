<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the trainer picked at approval, once the private session is paid
 * (prompt 15B): date, time, riders and the run sheet.
 */
final class PrivateSessionAssigned extends PrivateRequestNotification
{
    public static function type(): string
    {
        return 'private_session_assigned';
    }

    public static function label(): string
    {
        return 'Private session confirmed (trainer)';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('Private session confirmed: '.$request->label())
            ->markdown('mail.private-session-assigned', ['request' => $request, 'runSheet' => $request->slot_id === null ? route('team.sessions.index') : route('team.sessions.show', $request->slot_id)]);
    }
}
