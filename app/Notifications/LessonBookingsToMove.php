<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\RideEvents\RideEventResource;
use App\Models\Booking;
use App\Models\Slot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To every admin (decided 2026-11-22): an event date closed lesson slots
 * that already had bookings. They are never cancelled automatically; this
 * lists them to move.
 */
final class LessonBookingsToMove extends CustomerNotification
{
    /**
     * @param  Collection<int, Booking>  $bookings
     */
    public function __construct(public readonly Slot $occurrence, public readonly Collection $bookings)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'lesson_bookings_to_move';
    }

    public static function label(): string
    {
        return 'Lesson bookings to move (event day)';
    }

    public function subject(): Model
    {
        return $this->occurrence;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->occurrence->loadMissing('rideEvent');

        return (new MailMessage)
            ->subject(count($this->bookings).' lesson '.(count($this->bookings) === 1 ? 'booking' : 'bookings').' to move for '.($this->occurrence->rideEvent->title ?? 'an event'))
            ->markdown('mail.lesson-bookings-to-move', [
                'occurrence' => $this->occurrence,
                'bookings' => $this->bookings,
                'eventUrl' => $this->occurrence->rideEvent === null ? null : RideEventResource::getUrl('edit', ['record' => $this->occurrence->rideEvent]),
            ]);
    }
}
