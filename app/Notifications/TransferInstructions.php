<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SettingKey;
use App\Models\Order;
use App\Support\ManageLink;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a guest who chose bank transfer (guest checkout): the bank details,
 * the reference and deadline, and the manage link to come back and upload
 * the screenshot. Signed-in customers find all of this in their account.
 */
final class TransferInstructions extends CustomerNotification
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'transfer_instructions';
    }

    public static function label(): string
    {
        return 'Bank transfer details';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = app(Settings::class);

        return (new MailMessage)
            ->subject('Bank transfer details for '.$this->order->reference)
            ->markdown('mail.transfer-instructions', [
                'order' => $this->order,
                'url' => ManageLink::forOrder($this->order),
                'timezone' => (string) config('app.display_timezone'),
                'bank' => [
                    'Account name' => $settings->string(SettingKey::BankAccountName),
                    'Bank' => $settings->string(SettingKey::BankName),
                    'IBAN' => $settings->string(SettingKey::BankIban),
                    'SWIFT' => $settings->string(SettingKey::BankSwift),
                ],
            ]);
    }
}
