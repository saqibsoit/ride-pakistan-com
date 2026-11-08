<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Slot;
use App\Models\WeatherFetch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/** To each admin: the weather forecast has failed to load for 12 hours (milestone 10f). */
final class WeatherForecastsFailing extends CustomerNotification
{
    public function __construct(
        public readonly WeatherFetch $firstFailure,
        public readonly ?CarbonImmutable $lastSuccessAt,
        public readonly string $error,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'weather_forecasts_failing';
    }

    public static function label(): string
    {
        return 'Weather forecasts failing (admins)';
    }

    public function subject(): Model
    {
        return $this->firstFailure;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tz = Slot::displayTimezone();

        return (new MailMessage)
            ->subject('Weather forecasts are failing')
            ->markdown('mail.weather-forecasts-failing', [
                'since' => $this->firstFailure->attempted_at->setTimezone($tz)->format('D j M Y, H:i'),
                'lastSuccess' => $this->lastSuccessAt?->setTimezone($tz)->format('D j M Y, H:i'),
                'error' => $this->error,
            ]);
    }
}
