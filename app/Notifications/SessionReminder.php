<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Actions\Booking\CancellationCutoff;
use App\Enums\SettingKey;
use App\Models\Booking;
use App\Models\Slot;
use App\Support\Settings;
use App\Support\TicketQr;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * About 24 hours before a session (reminders:send): what to wear (riding
 * rules), arrival time when set, the exact location pin and the tickets.
 * One email per customer per session, covering all their riders.
 */
final class SessionReminder extends CustomerNotification
{
    /**
     * @param  Collection<int, Booking>  $bookings  the customer's confirmed bookings in the slot
     */
    public function __construct(public readonly Slot $slot, public readonly Collection $bookings)
    {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'session_reminder';
    }

    public static function label(): string
    {
        return 'Session reminder';
    }

    public function subject(): Model
    {
        return $this->slot;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = app(Settings::class);
        $tz = (string) config('app.display_timezone');
        $bookings = $this->bookings->loadMissing('rider');
        $arrival = $settings->int(SettingKey::ArrivalMinutes);

        return (new MailMessage)
            ->subject('See you '.$this->slot->starts_at->setTimezone($tz)->format('l').' at '.$this->slot->starts_at->setTimezone($tz)->format('g:i a').': your Ride Pakistan session')
            ->markdown('mail.session-reminder', [
                'slot' => $this->slot,
                'bookings' => $bookings,
                'timezone' => $tz,
                'rules' => $settings->policies(SettingKey::RidingRules),
                'arrivalAt' => $arrival === null || $arrival <= 0 ? null : $this->slot->starts_at->subMinutes($arrival)->setTimezone($tz),
                'mapPin' => $settings->string(SettingKey::MapPinUrl),
                'cutoffHours' => app(CancellationCutoff::class)->hours(),
                'qr' => $bookings->mapWithKeys(fn (Booking $b): array => [$b->id => TicketQr::png($b, 6)])->all(),
            ]);
    }
}
