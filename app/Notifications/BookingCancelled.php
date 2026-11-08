<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CalendarMethod;
use App\Models\Booking;
use App\Notifications\Channels\WhatsAppMessage;
use App\Notifications\Concerns\AttachesCalendar;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * One booking was cancelled, by the customer or an admin (milestone 10d).
 * Says whether the credit came back and carries a cancelling .ics that
 * removes the entry from Apple Calendar or Outlook. A whole-slot
 * cancellation sends SlotCancelledNotice instead.
 */
final class BookingCancelled extends CustomerNotification
{
    use AttachesCalendar;

    public function __construct(public readonly Booking $booking, public readonly bool $creditReturned)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'booking_cancelled';
    }

    public static function label(): string
    {
        return 'Booking cancelled';
    }

    public function subject(): Model
    {
        return $this->booking;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking->loadMissing(['slot', 'rider']);

        $mail = (new MailMessage)
            ->subject('Booking cancelled: '.$booking->slot->label())
            ->markdown('mail.booking-cancelled', [
                'booking' => $booking,
                'creditReturned' => $this->creditReturned,
                'usedCredit' => $booking->usesCredit(),
            ]);

        return $this->attachCalendar($mail, [$booking], CalendarMethod::Cancel);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('Ride Pakistan: the booking for '.$this->booking->rider->name.' on '.$this->booking->slot->label().' is cancelled.');
    }
}
