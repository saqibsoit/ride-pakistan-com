<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Support\Events\EventBooking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent once per order before the balance is due (events:balance-reminders,
 * events.balance_reminder_hours [48] ahead). Decided 2026-11-22.
 */
final class EventBalanceReminder extends CustomerNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'event_balance_reminder';
    }

    public static function label(): string
    {
        return 'Event balance reminder';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $event = EventBooking::for($this->order);

        return (new MailMessage)
            ->subject('Your balance for '.$this->order->productName().' is due soon')
            ->markdown('mail.event-balance-reminder', ['order' => $this->order, 'event' => $event]);
    }
}
