<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

/** A WhatsApp text message (GT360 later). Plain text, no personal data beyond the recipient's own. */
final class WhatsAppMessage
{
    public function __construct(public readonly string $text) {}
}
