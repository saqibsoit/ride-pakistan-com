<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Referral;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To the referrer: a friend they referred has ridden, and credit was added (milestone 10e). */
final class ReferralRewardEarned extends CustomerNotification
{
    public function __construct(public readonly Referral $referral, public readonly int $amountMinor)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'referral_reward_earned';
    }

    public static function label(): string
    {
        return 'Referral reward earned';
    }

    public function subject(): Model
    {
        return $this->referral;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('You\'ve earned '.Money::format($this->amountMinor).' of Ride Pakistan credit')
            ->markdown('mail.referral-reward-earned', ['amountMinor' => $this->amountMinor]);
    }
}
