<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderBalance;
use App\Support\Events\EventBooking;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** Receipt for an event balance paid online or recorded by an admin (decided 2026-11-22). */
final class EventBalancePaid extends CustomerNotification
{
    public function __construct(public readonly Order $order, public readonly OrderBalance $balance)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'event_balance_paid';
    }

    public static function label(): string
    {
        return 'Event balance paid';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Balance received: '.$this->order->productName().' ('.$this->order->reference.')')
            ->markdown('mail.event-balance-paid', [
                'order' => $this->order,
                'balance' => $this->balance->refresh(),
                'event' => EventBooking::for($this->order->refresh()),
            ]);
    }
}
