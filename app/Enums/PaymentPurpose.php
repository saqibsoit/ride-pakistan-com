<?php

declare(strict_types=1);

namespace App\Enums;

/** What a payments row paid for: the order itself (or its deposit), or an event balance. */
enum PaymentPurpose: string
{
    case Order = 'order';
    case Balance = 'balance';
}
