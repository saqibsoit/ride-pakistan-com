<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\VerifyTransfers;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To each admin: a transfer is waiting in the verification queue. */
final class TransferToVerify extends CustomerNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'transfer_to_verify';
    }

    public static function label(): string
    {
        return 'Transfer to verify (admins)';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->order->loadMissing(['user', 'product']);

        return (new MailMessage)
            ->subject('Transfer to verify: '.$this->order->reference)
            ->markdown('mail.transfer-to-verify', ['order' => $this->order, 'queueUrl' => VerifyTransfers::getUrl()]);
    }
}
