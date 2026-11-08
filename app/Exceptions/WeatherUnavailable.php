<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The forecast could not be fetched or read (milestone 10f). Never shown to customers. */
final class WeatherUnavailable extends RuntimeException
{
    public static function http(int $status): self
    {
        return new self(sprintf('Open-Meteo answered HTTP %d.', $status));
    }

    public static function connection(string $message): self
    {
        return new self('Could not reach Open-Meteo: '.mb_substr($message, 0, 180));
    }

    public static function malformed(string $what): self
    {
        return new self('Unexpected Open-Meteo response: '.$what.'.');
    }
}
