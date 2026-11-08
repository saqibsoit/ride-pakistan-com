<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SettingKey;
use App\Enums\WeatherNoticeTemplate;
use App\Models\Booking;
use App\Models\Slot;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a customer with confirmed bookings in a slot: an admin's weather
 * update or "session moved" message (milestone 10f, SendWeatherNotice).
 * One per account holder, listing their riders. Cancelling is not sent
 * this way: it goes through the slot cancellation and its own email.
 */
final class WeatherNotice extends CustomerNotification
{
    /**
     * @param  Collection<int, Booking>  $bookings  the customer's confirmed bookings in the slot
     */
    public function __construct(
        public readonly Slot $slot,
        public readonly WeatherNoticeTemplate $template,
        public readonly string $message,
        public readonly Collection $bookings,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'weather_notice';
    }

    public static function label(): string
    {
        return 'Weather notice';
    }

    public function subject(): Model
    {
        return $this->slot;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $bookings = $this->bookings->loadMissing('rider');
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $this->message) ?: []), static fn (string $p): bool => $p !== ''));

        return (new MailMessage)
            ->subject($this->template->subject().': '.$this->slot->label())
            ->markdown('mail.weather-notice', [
                'heading' => $this->template->subject(),
                'slot' => $this->slot,
                'paragraphs' => $paragraphs,
                'bookings' => $bookings,
                'contactEmail' => app(Settings::class)->string(SettingKey::Email),
            ]);
    }
}
