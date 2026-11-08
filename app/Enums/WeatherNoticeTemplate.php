<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Templates of the customer weather notice an admin sends from a slot
 * (milestone 10f). The text of each is a setting. "Cancelled" cancels the
 * slot through ChangeSlotStatus; its email is the usual cancellation email.
 */
enum WeatherNoticeTemplate: string
{
    case Update = 'update';
    case Moved = 'moved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Update => 'Weather update',
            self::Moved => 'Session moved',
            self::Cancelled => 'Session cancelled (cancels the slot)',
        };
    }

    public function subject(): string
    {
        return match ($this) {
            self::Update => 'Weather update',
            self::Moved => 'Your session is moving',
            self::Cancelled => 'Session cancelled',
        };
    }

    public function setting(): SettingKey
    {
        return match ($this) {
            self::Update => SettingKey::WeatherTemplateUpdate,
            self::Moved => SettingKey::WeatherTemplateMoved,
            self::Cancelled => SettingKey::WeatherTemplateCancelled,
        };
    }

    /** Longest message: a cancellation reason is stored on the slot (500). */
    public function maxLength(): int
    {
        return $this === self::Cancelled ? 500 : 2000;
    }
}
