<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CalendarMethod;
use App\Models\Booking;
use App\Models\Slot;
use App\Notifications\Concerns\AttachesCalendar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A slot was cancelled by an admin (PLAN §2, milestone 8). Credits come
 * back to the pack; directly paid bookings are refunded or rebooked by the
 * team (the order is flagged for review). One email per customer.
 */
final class SlotCancelledNotice extends CustomerNotification
{
    use AttachesCalendar;

    /**
     * @param  Collection<int, Booking>  $bookings  the customer's bookings that were cancelled
     */
    public function __construct(
        public readonly Slot $slot,
        public readonly Collection $bookings,
        public readonly int $creditsReturned,
        public readonly bool $paidBookings,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'slot_cancelled';
    }

    public static function label(): string
    {
        return 'Session cancelled';
    }

    public function subject(): Model
    {
        return $this->slot;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookings = $this->bookings->loadMissing(['rider', 'order', 'creditPack.order.product']);

        $mail = (new MailMessage)
            ->subject('Session cancelled: '.$this->slot->label())
            ->markdown('mail.slot-cancelled', [
                'slot' => $this->slot,
                'bookings' => $bookings,
                'reason' => $this->slot->cancel_reason,
                'creditsReturned' => $this->creditsReturned,
                'paidBookings' => $this->paidBookings,
                'rebookUrl' => route('book', ['use' => 'credits']),
            ]);

        // Removes the session from Apple Calendar or Outlook (milestone 10d).
        return $bookings->isEmpty() ? $mail : $this->attachCalendar($mail, $bookings, CalendarMethod::Cancel);
    }
}
