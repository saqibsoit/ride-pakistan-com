<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\Slot;
use App\Support\ReviewLink;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * After a session the riders attended (thankyou:send): thanks, a signed
 * link to review the session on our site (milestone 10d), and a Google
 * review link when contact.google_review_url is set.
 */
final class ThankYou extends CustomerNotification
{
    /**
     * @param  Collection<int, Booking>  $bookings  the customer's attended bookings in the slot
     */
    public function __construct(public readonly Slot $slot, public readonly Collection $bookings)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'thank_you';
    }

    public static function label(): string
    {
        return 'Thank-you after the session';
    }

    public function subject(): Model
    {
        return $this->slot;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Thanks for riding with us')
            ->markdown('mail.thank-you', [
                'slot' => $this->slot,
                'riders' => $this->bookings->loadMissing('rider')->map(fn (Booking $b): string => $b->rider->name)->unique()->values()->all(),
                'reviewUrl' => app(Settings::class)->string(SettingKey::GoogleReviewUrl),
                // Signed, no sign-in needed (milestone 10d). One review per customer per session.
                'siteReviewUrl' => ($first = $this->bookings->first()) === null ? null : ReviewLink::for($first),
            ]);
    }
}
