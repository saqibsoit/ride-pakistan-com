<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CreditPack;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** A pack with unused credits expires in a week (credits:remind). Sent once per pack. */
final class CreditsExpiringSoon extends CustomerNotification
{
    public function __construct(public readonly CreditPack $pack)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'credits_expiring';
    }

    public static function label(): string
    {
        return 'Credits expiring soon';
    }

    public function subject(): Model
    {
        return $this->pack;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $left = $this->pack->creditsRemaining();

        return (new MailMessage)
            ->subject('Your '.$left.' riding '.($left === 1 ? 'credit expires' : 'credits expire').' on '.$this->pack->expires_at->setTimezone((string) config('app.display_timezone'))->format('j M'))
            ->markdown('mail.credits-expiring', [
                'pack' => $this->pack,
                'left' => $left,
                'timezone' => (string) config('app.display_timezone'),
            ]);
    }
}
