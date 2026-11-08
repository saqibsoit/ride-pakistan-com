<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a one-time sign-in code is sent. Only email exists today; a phone
 * (SMS/WhatsApp) channel implements the same LoginCodeChannel contract.
 */
enum LoginCodeChannelType: string
{
    case Email = 'email';
}
