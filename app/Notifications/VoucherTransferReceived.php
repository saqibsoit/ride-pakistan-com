<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the customer once the transfer proof for a gift voucher is in (milestone 10e). */
final class VoucherTransferReceived extends CustomerNotification
{
    public function __construct(public readonly VoucherOrder $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'voucher_transfer_received';
    }

    public static function label(): string
    {
        return 'Gift voucher transfer received';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We\'ve received your transfer: '.$this->order->reference)
            ->markdown('mail.voucher-transfer-received', ['order' => $this->order]);
    }
}
