<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SettingKey;
use App\Models\Booking;
use App\Notifications\Channels\WhatsAppMessage;
use App\Notifications\Concerns\AttachesCalendar;
use App\Support\Settings;
use App\Support\TicketQr;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * "You're booked" for bookings without a payment (milestone 10d): booked
 * with package credits by the customer or an admin, or a no-charge booking
 * an admin added. Tickets with QR codes, the map pin (confirmed bookings
 * only) and the session as .ics plus a Google Calendar link. Paid orders
 * get OrderConfirmed instead.
 */
final class BookingConfirmed extends CustomerNotification
{
    use AttachesCalendar;

    /**
     * @param  Collection<int, Booking>  $bookings  one customer's new bookings in one slot
     */
    public function __construct(public readonly Collection $bookings)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'booking_confirmed';
    }

    public static function label(): string
    {
        return 'Booking confirmed (credits or no charge)';
    }

    public function subject(): ?Model
    {
        return $this->bookings->first();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookings = $this->bookings->loadMissing(['slot', 'rider', 'creditPack'])->values();

        $mail = (new MailMessage)
            ->subject('Booking confirmed: '.$bookings->first()?->slot->label())
            ->markdown('mail.booking-confirmed', [
                'bookings' => $bookings,
                'mapPin' => app(Settings::class)->string(SettingKey::MapPinUrl),
                'helmet' => app(Settings::class)->string(SettingKey::HelmetNotice),
                'qr' => $bookings->mapWithKeys(fn (Booking $b): array => [$b->id => TicketQr::png($b, 6)])->all(),
                'calendar' => $this->googleLinks($bookings),
                'timezone' => (string) config('app.display_timezone'),
            ]);

        foreach ($bookings as $booking) {
            $mail->attachData(TicketQr::png($booking), 'ticket-'.$booking->ticket_code.'.png', ['mime' => 'image/png']);
        }

        return $this->attachCalendar($mail, $bookings);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('Ride Pakistan: you are booked for '.$this->bookings->first()?->slot->label().'. Your tickets are in your email and account.');
    }
}
