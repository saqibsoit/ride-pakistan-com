<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the customer: the transfer was not accepted, with the reason. */
final class TransferRejected extends CustomerNotification
{
    public function __construct(public readonly Order $order, public readonly string $reason)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'transfer_rejected';
    }

    public static function label(): string
    {
        return 'Bank transfer not accepted';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We couldn\'t confirm your transfer: '.$this->order->reference)
            ->markdown('mail.transfer-rejected', ['order' => $this->order, 'reason' => $this->reason]);
    }
}
