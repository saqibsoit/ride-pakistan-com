<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Booked = 'booked';
    case Lost = 'lost';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
