<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\WaitlistEntry;
use App\Notifications\Channels\WhatsAppMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Seats came free in a session the customer is waiting for (milestone
 * 10d). They are held for them until the offer expires; the link opens the
 * booking flow on that slot with their rider count.
 */
final class WaitlistOffer extends CustomerNotification
{
    public function __construct(public readonly WaitlistEntry $entry)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'waitlist_offer';
    }

    public static function label(): string
    {
        return 'Waitlist: seats held for you';
    }

    public function subject(): Model
    {
        return $this->entry;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry->loadMissing('slot');

        return (new MailMessage)
            ->subject('A place opened up: '.$entry->slot->label())
            ->markdown('mail.waitlist-offer', [
                'entry' => $entry,
                'expires' => $entry->offer_expires_at?->setTimezone((string) config('app.display_timezone'))->format('D j M, H:i'),
                'bookUrl' => self::bookUrl($entry),
            ]);
    }

    public static function bookUrl(WaitlistEntry $entry): string
    {
        return route('book', ['slot' => $entry->slot_id, 'riders' => $entry->seats]);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('Ride Pakistan: a place opened up on '.$this->entry->slot->label().'. It is held for you for a short time; book from the link in your email.');
    }
}
