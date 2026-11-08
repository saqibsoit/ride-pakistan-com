<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\VerifyVoucherTransfers;
use App\Models\VoucherOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To each admin: a gift voucher transfer is waiting for review (milestone 10e). */
final class VoucherTransferToVerify extends CustomerNotification
{
    public function __construct(public readonly VoucherOrder $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'voucher_transfer_to_verify';
    }

    public static function label(): string
    {
        return 'Gift voucher transfer to verify (admins)';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing('user');

        return (new MailMessage)
            ->subject('Voucher transfer to verify: '.$this->order->reference)
            ->markdown('mail.voucher-transfer-to-verify', ['order' => $this->order, 'queueUrl' => VerifyVoucherTransfers::getUrl()]);
    }
}
