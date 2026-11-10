<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the customer: their payment arrived, but the booking couldn't be
 * confirmed because the session is no longer bookable (closed, cancelled,
 * full or booked privately). A card payment has been refunded
 * automatically; a bank transfer (or a card refund that failed) is
 * flagged for the team, who will move or refund it.
 */
final class OrderNotFulfilled extends CustomerNotification
{
    public function __construct(public readonly Order $order, public readonly bool $refunded)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'order_not_fulfilled';
    }

    public static function label(): string
    {
        return 'Payment received, booking not confirmed';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->refunded ? 'Refunded: ' : 'About your booking: ').$this->order->reference.' (Ride Pakistan)')
            ->markdown('mail.order-not-fulfilled', ['order' => $this->order, 'refunded' => $this->refunded]);
    }
}
