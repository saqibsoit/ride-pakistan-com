<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the customer: no transfer proof arrived by the deadline. */
final class TransferExpired extends CustomerNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'transfer_expired';
    }

    public static function label(): string
    {
        return 'Booking expired (no transfer)';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your booking has expired: '.$this->order->reference)
            ->markdown('mail.transfer-expired', ['order' => $this->order]);
    }
}
