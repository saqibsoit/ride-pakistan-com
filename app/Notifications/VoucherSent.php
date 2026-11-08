<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the buyer of a scheduled gift voucher, on the day it goes out (milestone 10e). */
final class VoucherSent extends CustomerNotification
{
    public function __construct(public readonly Voucher $voucher)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'voucher_sent';
    }

    public static function label(): string
    {
        return 'Gift voucher sent (to the buyer)';
    }

    public function subject(): Model
    {
        return $this->voucher;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your gift voucher is on its way to '.$this->voucher->recipient_name)
            ->markdown('mail.voucher-sent', ['voucher' => $this->voucher]);
    }
}
