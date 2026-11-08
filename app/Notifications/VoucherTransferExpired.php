<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the customer: no transfer proof arrived before the deadline for a gift voucher. */
final class VoucherTransferExpired extends CustomerNotification
{
    public function __construct(public readonly VoucherOrder $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'voucher_transfer_expired';
    }

    public static function label(): string
    {
        return 'Gift voucher purchase expired';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your gift voucher purchase has expired: '.$this->order->reference)
            ->markdown('mail.voucher-transfer-expired', ['order' => $this->order]);
    }
}
