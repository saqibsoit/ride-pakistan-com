<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\Slot;
use App\Support\Events\EventBooking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * We cancelled an event date (decided 2026-11-22): the customer chooses a
 * full refund or another date on their booking page (the manage link).
 */
final class EventDateCancelled extends CustomerNotification
{
    public function __construct(public readonly Order $order, public readonly Slot $slot)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'event_date_cancelled';
    }

    public static function label(): string
    {
        return 'Event date cancelled';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We’ve had to cancel '.$this->order->productName().' on '.$this->slot->localStartsAt()->format('D j M'))
            ->markdown('mail.event-date-cancelled', [
                'order' => $this->order,
                'slot' => $this->slot,
                'event' => EventBooking::for($this->order),
                'reason' => $this->slot->cancel_reason,
            ]);
    }
}
