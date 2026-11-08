<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Voucher;
use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the buyer once the payment is confirmed (milestone 10e): receipt and when the voucher is sent. */
final class VoucherPurchaseConfirmed extends CustomerNotification
{
    public function __construct(public readonly VoucherOrder $order, public readonly Voucher $voucher)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'voucher_purchase_confirmed';
    }

    public static function label(): string
    {
        return 'Gift voucher receipt';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment received: gift voucher '.$this->order->reference)
            ->markdown('mail.voucher-purchase-confirmed', ['order' => $this->order, 'voucher' => $this->voucher]);
    }
}
