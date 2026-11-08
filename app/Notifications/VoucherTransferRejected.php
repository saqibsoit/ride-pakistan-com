<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the customer: an admin rejected the transfer for a gift voucher, with the reason. */
final class VoucherTransferRejected extends CustomerNotification
{
    public function __construct(public readonly VoucherOrder $order, public readonly string $reason)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'voucher_transfer_rejected';
    }

    public static function label(): string
    {
        return 'Gift voucher transfer rejected';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('We couldn\'t confirm your transfer: '.$this->order->reference)
            ->markdown('mail.voucher-transfer-rejected', ['order' => $this->order, 'reason' => $this->reason]);
    }
}
