<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * Forecast level for a session (milestone 10f). Watch: one threshold
 * crossed. Warning: a thunderstorm, or two or more thresholds. The team is
 * emailed whenever a slot's level changes.
 */
enum WeatherSeverity: string
{
    case Clear = 'clear';
    case Watch = 'watch';
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Clear => 'Clear',
            self::Watch => 'Watch',
            self::Warning => 'Warning',
        };
    }

    /** Variant of <x-badge> and its <x-glyph> (status is never shown by colour alone). */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Clear => 'success',
            self::Watch => 'warning',
            self::Warning => 'error',
        };
    }

    /** Filament badge colour for the admin. */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Clear => 'success',
            self::Watch => 'warning',
            self::Warning => 'danger',
        };
    }

    /** Filament icon shown with the label, so the level reads without colour. */
    public function filamentIcon(): Heroicon
    {
        return match ($this) {
            self::Clear => Heroicon::OutlinedCheckCircle,
            self::Watch => Heroicon::OutlinedExclamationCircle,
            self::Warning => Heroicon::OutlinedExclamationTriangle,
        };
    }

    /** Is this level worse than the other one? */
    public function rank(): int
    {
        return match ($this) {
            self::Clear => 0,
            self::Watch => 1,
            self::Warning => 2,
        };
    }
}
