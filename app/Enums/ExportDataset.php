<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What an admin can export (milestone 10b), and which date the range
 * filters on: orders, payments, refunds and leads by when they were
 * created; bookings by the session date.
 */
enum ExportDataset: string implements HasLabel
{
    case Orders = 'orders';
    case Payments = 'payments';
    case Refunds = 'refunds';
    case Bookings = 'bookings';
    case Leads = 'leads';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function dateLabel(): string
    {
        return match ($this) {
            self::Orders => 'order created',
            self::Payments => 'payment received',
            self::Refunds => 'refund recorded',
            self::Bookings => 'session date',
            self::Leads => 'lead received',
        };
    }
}
