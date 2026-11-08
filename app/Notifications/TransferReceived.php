<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the customer once their transfer proof is in (PLAN §3.2). */
final class TransferReceived extends CustomerNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'transfer_received';
    }

    public static function label(): string
    {
        return 'Bank transfer received';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We\'ve received your transfer: '.$this->order->reference)
            ->markdown('mail.transfer-received', ['order' => $this->order]);
    }
}
