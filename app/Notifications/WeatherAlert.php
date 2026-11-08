<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\BookingStatus;
use App\Enums\WeatherSeverity;
use App\Filament\Resources\Slots\SlotResource;
use App\Models\Slot;
use App\Models\SlotWeather;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the team (weather.recipients): a session's forecast level changed
 * (milestone 10f). The level and conditions are frozen when it is sent.
 * Admins also get the link to cancel or move the slot in admin.
 */
final class WeatherAlert extends CustomerNotification
{
    /**
     * @param  array{crossed: list<array{key: string, label: string, value: float|int, unit: string, at: string}>, hours: list<array<string, mixed>>}  $conditions
     */
    public function __construct(
        public readonly Slot $slot,
        public readonly WeatherSeverity $severity,
        public readonly ?WeatherSeverity $previous,
        public readonly array $conditions,
    ) {
        parent::__construct();
    }

    public static function type(): string
    {
        return 'weather_alert';
    }

    public static function label(): string
    {
        return 'Weather alert (team)';
    }

    public function subject(): Model
    {
        return $this->slot;
    }

    public function heading(): string
    {
        return match ($this->severity) {
            WeatherSeverity::Warning => 'Weather warning',
            WeatherSeverity::Watch => 'Weather watch',
            WeatherSeverity::Clear => 'Weather all clear',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tz = Slot::displayTimezone();
        $slot = $this->slot;

        $riders = $slot->bookings()->whereIn('status', BookingStatus::seatOccupyingValues())->count();

        $crossed = array_map(static fn (array $c): array => [
            'text' => $c['key'] === 'thunderstorm'
                ? 'Thunderstorm (code '.$c['value'].')'
                : ucfirst($c['label']).' '.SlotWeather::number($c['value']).$c['unit'],
            'at' => CarbonImmutable::parse($c['at'])->setTimezone($tz)->format('H:i'),
        ], $this->conditions['crossed']);

        $hours = array_map(static fn (array $h): array => [
            'at' => CarbonImmutable::parse((string) $h['at'])->setTimezone($tz)->format('H:i'),
            'rain' => $h['rain_probability'] === null ? '–' : $h['rain_probability'].'%',
            'mm' => $h['precipitation_mm'] === null ? '–' : SlotWeather::number((float) $h['precipitation_mm']).' mm',
            'gusts' => $h['wind_gusts_kmh'] === null ? '–' : SlotWeather::number((float) $h['wind_gusts_kmh']).' km/h',
            'temp' => $h['temperature_c'] === null ? '–' : SlotWeather::number((float) $h['temperature_c']).'°C',
            'code' => $h['weather_code'] ?? '–',
        ], $this->conditions['hours']);

        $isAdmin = $notifiable instanceof User && $notifiable->isAdmin();

        return (new MailMessage)
            ->subject($this->heading().': '.$slot->localStartsAt()->format('D j M, H:i').'–'.$slot->localEndsAt()->format('H:i'))
            ->markdown('mail.weather-alert', [
                'heading' => $this->heading(),
                'slot' => $slot,
                'severity' => $this->severity,
                'previous' => $this->previous,
                'crossed' => $crossed,
                'hours' => $hours,
                'riders' => $riders,
                'runSheetUrl' => route('team.sessions.show', $slot),
                'adminUrl' => $isAdmin ? SlotResource::getUrl('view', ['record' => $slot]) : null,
            ]);
    }
}
