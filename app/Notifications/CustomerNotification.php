<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Channels\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * Base for every customer and team notification (milestone 8). Queued and
 * sent only after the surrounding transaction commits. Email always;
 * WhatsApp too once notifications.whatsapp.enabled is on (a no-op channel
 * until GT360 is connected). Sent through App\Support\Notifier, which logs
 * each one and prevents duplicates.
 */
abstract class CustomerNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** notification_log row, set by Notifier; marked sent when mail goes out. */
    public ?int $logId = null;

    public function __construct()
    {
        $this->afterCommit();
    }

    /** Short stable name for the log and resend menu, e.g. "order_confirmed". */
    abstract public static function type(): string;

    /** Human label for the admin resend menu. */
    abstract public static function label(): string;

    /** The record it is about (order, booking, slot, pack), for the log. */
    abstract public function subject(): ?Model;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return config('notifications.whatsapp.enabled') === true
            ? ['mail', WhatsAppChannel::class]
            : ['mail'];
    }

    public function toWhatsApp(object $notifiable): ?WhatsAppMessage
    {
        return null;
    }
}
