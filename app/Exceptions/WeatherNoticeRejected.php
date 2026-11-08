<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** A customer weather notice that can't be sent (milestone 10f). */
final class WeatherNoticeRejected extends DomainException
{
    public static function slotNotUpcoming(): self
    {
        return new self('Weather notices can only be sent for upcoming sessions that aren’t cancelled.');
    }

    public static function noRiders(): self
    {
        return new self('Nobody has a confirmed booking in this session, so there is no one to tell.');
    }

    public static function emptyMessage(): self
    {
        return new self('Write a message first.');
    }

    public static function tooLong(int $max): self
    {
        return new self(sprintf('The message can be at most %d characters.', $max));
    }

    public static function tooMany(int $minutes): self
    {
        return new self(sprintf('Too many notices for this session. Try again in %d minutes.', max(1, $minutes)));
    }

    public static function alreadySent(): self
    {
        return new self('This exact message was already sent to these riders.');
    }
}
