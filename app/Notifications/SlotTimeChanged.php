<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SlotChangeNotice;
use App\Notifications\Channels\WhatsAppMessage;
use App\Notifications\Concerns\AttachesCalendar;
use App\Support\SlotChangeLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The time of a booked session changed in the bulk schedule editor
 * (prompt 15A). One email per customer per session: old and new time,
 * the riders, the updated .ics, and three signed links (keep the new time,
 * move, cancel) that work until the new start.
 */
final class SlotTimeChanged extends CustomerNotification
{
    use AttachesCalendar;

    public function __construct(public readonly SlotChangeNotice $notice)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'slot_time_changed';
    }

    public static function label(): string
    {
        return 'Session time changed (keep, move or cancel)';
    }

    public function subject(): Model
    {
        return $this->notice;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookings = $this->notice->bookings()->loadMissing('slot');

        $mail = (new MailMessage)
            ->subject('New time for your session: '.$this->notice->newLabel())
            ->markdown('mail.slot-time-changed', [
                'notice' => $this->notice,
                'bookings' => $bookings,
                'calendar' => $this->googleLinks($bookings),
                'acceptUrl' => SlotChangeLink::show($this->notice).'#keep',
                'moveUrl' => SlotChangeLink::move($this->notice),
                'cancelUrl' => SlotChangeLink::show($this->notice).'#cancel',
                'paid' => $bookings->contains(fn ($b): bool => $b->credit_pack_id === null && $b->order_id !== null),
            ]);

        return $bookings->isEmpty() ? $mail : $this->attachCalendar($mail, $bookings);
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        return new WhatsAppMessage('Ride Pakistan: your session on '.$this->notice->oldLabel().' now runs '.$this->notice->newLabel().'. Check your email to keep, move or cancel.');
    }
}
