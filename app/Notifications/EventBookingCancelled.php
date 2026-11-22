<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An event booking was cancelled (decided 2026-11-22): by the customer, or
 * with a full refund after we cancelled the date. Says what comes back and
 * how.
 */
final class EventBookingCancelled extends CustomerNotification
{
    public function __construct(
        public readonly Order $order,
        public readonly int $refundMinor,
        public readonly bool $byBankOrCash,
        public readonly bool $byUs,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'event_booking_cancelled';
    }

    public static function label(): string
    {
        return 'Event booking cancelled';
    }

    public function subject(): Model
    {
        return $this->order;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Booking cancelled: '.$this->order->productName().' ('.$this->order->reference.')')
            ->markdown('mail.event-booking-cancelled', [
                'order' => $this->order,
                'refund' => $this->refundMinor,
                'manual' => $this->byBankOrCash,
                'byUs' => $this->byUs,
            ]);
    }
}
