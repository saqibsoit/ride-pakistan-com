<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Http\Controllers\PrintableVoucherController;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the recipient (milestone 10e): their gift voucher with its code,
 * value, expiry, the buyer's message and a link to the printable voucher.
 * Sent to an address, not an account (Notifier::sendToAddress).
 */
final class GiftVoucherReceived extends CustomerNotification
{
    public function __construct(public readonly Voucher $voucher)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'gift_voucher_received';
    }

    public static function label(): string
    {
        return 'Gift voucher (to the recipient)';
    }

    public function subject(): Model
    {
        return $this->voucher;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->voucher->loadMissing('voucherOrder.user');
        $from = $this->voucher->voucherOrder?->buyerName();

        return (new MailMessage)
            ->subject(($from !== null && $from !== '' ? $from.' sent you' : 'You\'ve received').' a Ride Pakistan gift voucher')
            ->markdown('mail.gift-voucher-received', [
                'voucher' => $this->voucher,
                'from' => $from,
                'message' => $this->voucher->voucherOrder?->message,
                'printUrl' => PrintableVoucherController::url($this->voucher),
            ]);
    }
}
