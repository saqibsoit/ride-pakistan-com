<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\User;
use App\Notifications\CustomerNotification;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp channel placeholder (GT360 later). Only used when
 * notifications.whatsapp.enabled is on, and even then it sends nothing:
 * it logs what would be sent (without the phone number) so the wiring
 * can be tested end to end.
 */
final class WhatsAppChannel
{
    public function send(object $notifiable, CustomerNotification $notification): void
    {
        $message = $notification->toWhatsApp($notifiable);

        if ($message === null || ! $notifiable instanceof User || blank($notifiable->phone)) {
            return;
        }

        Log::info('WhatsApp (not sent, provider not connected): '.$notification::class, [
            'user_id' => $notifiable->id,
            'length' => mb_strlen($message->text),
        ]);
    }
}
