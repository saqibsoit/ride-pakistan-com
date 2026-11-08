<?php

declare(strict_types=1);

namespace App\Support\Weather;

use App\Enums\SettingKey;
use App\Support\Settings;

/**
 * Alert thresholds (milestone 10f, admin settings). A value at the
 * threshold counts as crossed. A null threshold is switched off.
 */
final readonly class WeatherThresholds
{
    /**
     * @param  list<int>  $thunderCodes
     */
    public function __construct(
        public ?int $rainProbability,
        public ?float $precipitationMm,
        public ?int $windKmh,
        public ?int $heatC,
        public ?int $coldC,
        public array $thunderCodes,
    ) {}

    public static function fromSettings(Settings $settings): self
    {
        $codes = [];

        foreach ($settings->list(SettingKey::WeatherThunderCodes) as $code) {
            if (is_numeric($code)) {
                $codes[] = (int) $code;
            }
        }

        return new self(
            $settings->int(SettingKey::WeatherRainProbability),
            $settings->float(SettingKey::WeatherPrecipitationMm),
            $settings->int(SettingKey::WeatherWindKmh),
            $settings->int(SettingKey::WeatherHeatC),
            $settings->int(SettingKey::WeatherColdC),
            array_values(array_unique($codes)),
        );
    }
}
