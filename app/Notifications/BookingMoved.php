<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\Slot;
use App\Notifications\Channels\WhatsAppMessage;
use App\Notifications\Concerns\AttachesCalendar;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A booking moved to another slot, by the customer or an admin
 * (milestone 10d). Carries the updated .ics (same UID, higher SEQUENCE),
 * so the calendar entry moves instead of a second one appearing.
 */
final class BookingMoved extends CustomerNotification
{
    use AttachesCalendar;

    /**
     * @param  Collection<int, Booking>  $bookings  the bookings that moved (now in the new slot)
     */
    public function __construct(public readonly Collection $bookings, public readonly Slot $from)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'booking_moved';
    }

    public static function label(): string
    {
        return 'Booking moved (updated calendar file)';
    }

    public function subject(): ?Model
    {
        return $this->bookings->first();
    }

    /** One email per move: the sequence changes with every move. */
    public function dedupeKey(): string
    {
        $first = $this->bookings->first();

        return 'booking_moved:'.$first?->id.':'.$first?->calendar_sequence;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookings = $this->bookings->loadMissing(['slot', 'rider'])->values();

        $mail = (new MailMessage)
            ->subject('Booking moved to '.$bookings->first()?->slot->label())
            ->markdown('mail.booking-moved', [
                'bookings' => $bookings,
                'from' => $this->from,
                'mapPin' => app(Settings::class)->string(SettingKey::MapPinUrl),
                'calendar' => $this->googleLinks($bookings),
            ]);

        return $this->attachCalendar($mail, $bookings);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('Ride Pakistan: your booking moved from '.$this->from->label().' to '.$this->bookings->first()?->slot->label().'.');
    }
}
