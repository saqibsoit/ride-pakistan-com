<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SettingKey;

/**
 * wa.me links: the site's own chat button (number and message from
 * Settings) and a link to a customer's WhatsApp for the team.
 */
final class WhatsAppLink
{
    public function __construct(private readonly Settings $settings) {}

    /** The "Chat with us" link, or null while no number is set. */
    public function contact(): ?string
    {
        $message = $this->settings->string(SettingKey::WhatsAppMessage);

        return self::to($this->settings->string(SettingKey::WhatsAppNumber), $message === '' ? null : $message);
    }

    /** A wa.me link for any number (E.164 or digits), or null if it has no digits. */
    public static function to(?string $number, ?string $message = null): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', (string) $number);

        if (strlen($digits) < 8) {
            return null;
        }

        return 'https://wa.me/'.$digits.($message === null ? '' : '?text='.rawurlencode($message));
    }
}
